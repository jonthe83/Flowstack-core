<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/idnr.php';
require_once __DIR__ . '/ordrar_fortnox.php';
require_once __DIR__ . '/ordrar_payout.php';
require_once __DIR__ . '/bokmalen_ordrar_webhook.php';
require_once __DIR__ . '/ordrar_v3.php';

function jeab_ordrar_step_labels(): array
{
    return [
        'kund_bestallt' => 'Kund beställt',
        'fatt_alla_uppgifter' => 'Bekräftad',
        'foranmald' => 'Föranmäld',
        'bokad_tid' => 'Bokad tid',
        'bestalld' => 'Beställd',
        'order_skriven' => 'Order skriven',
        'faktura_skapad' => 'Faktura skapad',
    ];
}

function jeab_ordrar_url(array $extra = []): string
{
    $cacheKey = json_encode([
        $extra,
        (string) ($_GET['co'] ?? ''),
        (string) ($_GET['who'] ?? ''),
        (string) ($_GET['job'] ?? ''),
        (string) ($_GET['q'] ?? ''),
        (string) ($_SESSION['jeab_company'] ?? ''),
        (string) time(),
    ], JSON_UNESCAPED_UNICODE);
    if (is_string($cacheKey)) {
        $hit = $GLOBALS['_JEAB_ORDRAR_URL'][$cacheKey] ?? null;
        if (is_string($hit)) {
            return $hit;
        }
    }
    $q = ['p' => 'ordrar'];
    $q['co'] = jeab_normalize_company($extra['co'] ?? jeab_selected_company()) ?? jeab_selected_company();
    $id = (int) ($extra['id'] ?? $extra['open'] ?? 0);
    if ($id > 0) {
        $q['id'] = $id;
    }
    if (!empty($extra['flash'])) {
        $q['flash'] = (string) $extra['flash'];
    }
    $stop = trim((string) ($extra['stop'] ?? ''));
    if ($stop !== '') {
        $q['stop'] = $stop;
    }
    if (!empty($extra['refresh'])) {
        $q['refresh'] = '1';
    }
    if (array_key_exists('who', $extra)) {
        $who = trim((string) $extra['who']);
        if ($who !== '') {
            $q['who'] = $who;
        }
    }
    if (array_key_exists('job', $extra)) {
        $job = trim((string) $extra['job']);
        if ($job !== '') {
            $q['job'] = $job;
        }
    }
    $seek = trim((string) ($extra['q'] ?? ''));
    if ($seek !== '') {
        $q['q'] = $seek;
    }
    if (isset($q['who']) || isset($q['job'])) {
        // Stämpel: personvalet i adressen gäller bara medan man arbetar (jeab_who_url_fresh).
        $q['ws'] = (string) time();
    }
    $url = jeab_url('index.php?' . http_build_query($q));
    if (is_string($cacheKey)) {
        if (!isset($GLOBALS['_JEAB_ORDRAR_URL']) || !is_array($GLOBALS['_JEAB_ORDRAR_URL'])) {
            $GLOBALS['_JEAB_ORDRAR_URL'] = [];
        }
        $GLOBALS['_JEAB_ORDRAR_URL'][$cacheKey] = $url;
    }

    return $url;
}

/**
 * Personfilter och söktext som ska följa med från listan in i ordern och tillbaka.
 * who/job i adressen behålls även om de är äldre än förvalet, så Tillbaka inte
 * byter person eller tömmer sökningen.
 *
 * @return array<string, string>
 */
function jeab_ordrar_context_params(string $company): array
{
    $params = [];
    $hasWho = array_key_exists('who', $_GET);
    $hasJob = array_key_exists('job', $_GET);
    if ($hasWho || $hasJob) {
        $who = trim((string) ($_GET['who'] ?? ''));
        $job = trim((string) ($_GET['job'] ?? ''));
        $params['who'] = $who !== '' ? $who : 'all';
        $params['job'] = $job !== '' ? $job : 'all';
    } elseif (function_exists('jeab_filter_from_request') && function_exists('jeab_filter_url_params')) {
        $state = jeab_filter_from_request($company, true);
        $params = jeab_filter_url_params($state['who'] ?? null, $state['job'] ?? null);
    }
    $seek = trim((string) ($_GET['q'] ?? ''));
    if ($seek !== '') {
        $params['q'] = $seek;
    }
    return $params;
}

/**
 * External reads (Fortnox-order, address lookup, nätbolag, Solkoll, Graph calendar)
 * stay on for saves and an explicit Uppdatera (?refresh=1). A normal open
 * reads what is already stored. Step changes do not turn the reads back on.
 */
function jeab_ordrar_external_live(?bool $set = null): bool
{
    if ($set !== null) {
        $GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = $set;
    }
    if (!array_key_exists('_JEAB_ORDRAR_EXTERNAL_LIVE', $GLOBALS)) {
        if (function_exists('jeab_page_blocks_external') && jeab_page_blocks_external()) {
            return false;
        }
        return true;
    }
    return (bool) $GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'];
}

function jeab_ordrar_name(array $row): string
{
    if (function_exists('jeab_contacts_sole_view_for')) {
        $view = jeab_contacts_sole_view_for($row);
        if (is_array($view) && !empty($view['linked'])) {
            $picked = jeab_ordrar_named_huvud($row);
            if ($picked !== '') {
                return $picked;
            }
            $name = trim((string) ($view['name'] ?? ''));
            $stored = function_exists('jeab_name_address_order_names')
                ? jeab_name_address_order_names($row)
                : (function_exists('jeab_contacts_sole_row_stored_names') ? jeab_contacts_sole_row_stored_names($row) : []);
            if ($name === '' && function_exists('jeab_contacts_sole_log_order_only')) {
                foreach ($stored as $storedName) {
                    if (trim((string) $storedName) !== '') {
                        jeab_contacts_sole_log_order_only($row, 'name');
                        break;
                    }
                }
            }
            if (function_exists('jeab_name_address_pick_name')) {
                $emails = [(string) ($view['email'] ?? ''), (string) ($row['email'] ?? '')];
                if (function_exists('jeab_contacts_sole_row_emails')) {
                    foreach (jeab_contacts_sole_row_emails($row) as $email) {
                        $emails[] = (string) $email;
                    }
                }

                return jeab_name_address_pick_name($name, $stored, $emails);
            }

            return $name;
        }
        if (is_array($view) && trim((string) ($view['name'] ?? '')) !== '') {
            return $view['name'];
        }
    }
    $huvud = trim((string) ($row['huvudsokande'] ?? ''));
    if ($huvud !== '') {
        return $huvud;
    }
    $name = trim((string) ($row['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $applicant = is_array($row['applicant'] ?? null) ? $row['applicant'] : [];
    $name = trim((string) ($applicant['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return trim((string) ($row['customer_name'] ?? ''));
}

/**
 * Huvudsökande som rubriken ska visa efter ett manuellt val.
 * Kontakten behåller namnet när orderfältet skalas av.
 */
function jeab_ordrar_named_huvud(array $row): string
{
    $id = trim((string) ($row['contact_id'] ?? ''));
    if ($id !== '' && function_exists('jeab_contacts_sole_linked_contact')) {
        $contact = jeab_contacts_sole_linked_contact($id);
        if (is_array($contact)) {
            $name = trim((string) ($contact['huvudsokande'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }
    if (!empty($row['huvudsokande_manual'])) {
        return trim((string) ($row['huvudsokande'] ?? ''));
    }

    return '';
}

function jeab_ordrar_remember_huvud(string $contactId, string $name): void
{
    $contactId = trim($contactId);
    $name = trim($name);
    if ($contactId === '' || $name === '' || !function_exists('jeab_kontakter_mutate')) {
        return;
    }
    jeab_kontakter_mutate(static function (array &$contacts) use ($contactId, $name): void {
        $row = $contacts[$contactId] ?? null;
        if (!is_array($row)) {
            return;
        }
        $row['huvudsokande'] = $name;
        $row['id'] = $contactId;
        $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $contacts[$contactId] = $row;
    });
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($contactId);
    }
}

/**
 * Visning. Flaggan av lämnar raden orörd. Flaggan på läser kundfält från kontakten.
 * Tomt kontaktfält visas tomt. Produkt, nätägare, fastighet och pris rörs inte.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ordrar_display_row(array $row): array
{
    $started = microtime(true);
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_paint_order')) {
        $painted = jeab_contacts_sole_paint_order($row);
    } else {
        $painted = $row;
    }
    if (function_exists('jeab_timing_add')) {
        jeab_timing_add('display_row', microtime(true) - $started);
    }

    return $painted;
}

function jeab_ordrar_first_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    return trim((string) ($parts[0] ?? ''));
}

function jeab_ordrar_is_person_name_candidate(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    if (jeab_ordrar_looks_like_street($name) || jeab_ordrar_looks_like_beteckning($name)) {
        return false;
    }
    return true;
}

function jeab_ordrar_customer_name(?array $reminder): string
{
    if (!is_array($reminder)) {
        return '';
    }
    foreach (['customer_name', 'customer', 'name'] as $key) {
        $name = trim((string) ($reminder[$key] ?? ''));
        if (jeab_ordrar_is_person_name_candidate($name)) {
            return $name;
        }
    }
    $title = trim((string) ($reminder['title'] ?? ''));
    if (jeab_ordrar_is_person_name_candidate($title)) {
        return $title;
    }
    return '';
}

function jeab_ordrar_person_name(?array $reminder, array $open = []): string
{
    $name = jeab_ordrar_customer_name($reminder);
    if ($name !== '') {
        return $name;
    }
    $name = jeab_ordrar_customer_name($open);
    if ($name !== '') {
        return $name;
    }
    $applicant = is_array($open['applicant'] ?? null) ? $open['applicant'] : [];
    $appName = trim((string) ($applicant['name'] ?? ''));
    if (jeab_ordrar_is_person_name_candidate($appName)) {
        return $appName;
    }
    return '';
}

function jeab_ordrar_honor_huvudsokande_pick(array $row, string $first = ''): bool
{
    unset($first);
    if (empty($row['huvudsokande_manual'])) {
        return false;
    }
    return trim((string) ($row['huvudsokande'] ?? '')) !== '';
}

function jeab_ordrar_apply_primary_name(array $row, string $huvud): array
{
    $huvud = trim($huvud);
    if ($huvud === '') {
        return $row;
    }
    $row['huvudsokande'] = $huvud;
    $row['name'] = $huvud;
    if (!is_array($row['applicant'] ?? null)) {
        $row['applicant'] = ['name' => '', 'email' => '', 'phone' => ''];
    }
    $row['applicant']['name'] = $huvud;
    return $row;
}

function jeab_ordrar_format_pnr(string $pnr): string
{
    return jeab_pnr_normalize($pnr);
}

function jeab_ordrar_is_title_or_product_word(string $value, string $title): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    if (in_array($lower, ['laddbox', 'batteri', 'solceller', 'sol', 'köpt', 'kopt'], true)) {
        return true;
    }
    $titleFirst = jeab_ordrar_first_name($title);
    return $titleFirst !== '' && strcasecmp($value, $titleFirst) === 0;
}

function jeab_ordrar_looks_like_beteckning(string $value): bool
{
    return trim($value) !== '' && (bool) preg_match('/\d+\s*:\s*\d+/', $value);
}

function jeab_ordrar_looks_like_street(string $value): bool
{
    $value = trim($value);
    if ($value === '' || jeab_ordrar_looks_like_beteckning($value)) {
        return false;
    }
    return (bool) preg_match('/\p{L}{2,}.*\d/u', $value);
}

function jeab_ordrar_looks_like_person_name(string $value, string $customerName = ''): bool
{
    $value = trim($value);
    $customerName = trim($customerName);
    if ($value === '' || $customerName === '') {
        return false;
    }
    return jeab_ordrar_same_person($value, $customerName)
        || jeab_ordrar_name_contains($customerName, $value)
        || jeab_ordrar_name_contains($value, jeab_ordrar_first_name($customerName));
}

function jeab_ordrar_street_query(string $raw, string $customerName = '', string $ort = ''): string
{
    $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    $customerName = trim($customerName);
    $ort = trim($ort);
    if ($raw === '' || jeab_ordrar_looks_like_beteckning($raw)) {
        return '';
    }
    if ($customerName !== '') {
        $raw = trim((string) preg_replace('/(?:^|[\s,])+' . preg_quote($customerName, '/') . '\s*$/ui', '', $raw));
        $nameParts = preg_split('/\s+/u', $customerName) ?: [];
        $first = trim((string) ($nameParts[0] ?? ''));
        $last = trim((string) ($nameParts[count($nameParts) - 1] ?? ''));
        if ($first !== '' && $last !== '' && strcasecmp($first, $last) !== 0) {
            $raw = trim((string) preg_replace('/(?:^|[\s,])+' . preg_quote($first . ' ' . $last, '/') . '\s*$/ui', '', $raw));
        }
    }
    if (str_contains($raw, ',')) {
        $chunks = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $part): bool => $part !== ''));
        if (count($chunks) >= 3) {
            $tail = (string) $chunks[count($chunks) - 1];
            if (!preg_match('/\d/u', $tail) && (jeab_ordrar_looks_like_person_name($tail, $customerName) || preg_match('/\s/u', $tail))) {
                array_pop($chunks);
                $raw = implode(' ', $chunks);
            }
        }
    }
    $street = function_exists('jeab_reminder_street') ? jeab_reminder_street($raw) : $raw;
    $city = function_exists('jeab_reminder_parse_city') ? jeab_reminder_parse_city($raw) : '';
    if ($city !== '' && jeab_ordrar_looks_like_person_name($city, $customerName)) {
        $city = '';
    }
    if ($city === '' && $ort !== '' && !jeab_ordrar_looks_like_person_name($ort, $customerName)) {
        $city = $ort;
    }
    $query = '';
    $placeAlready = static function (string $text, string $place): bool {
        $place = trim($place);
        if ($place === '' || $text === '') {
            return false;
        }
        $fold = static function (string $value): string {
            return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        };
        $left = $fold($text);
        $right = $fold($place);

        return $left === $right || str_ends_with($left, ' ' . $right);
    };
    if ($street !== '' && $city !== '' && strcasecmp($street, $city) !== 0 && !$placeAlready($street, $city)) {
        $query = trim($street . ' ' . $city);
    } elseif ($street !== '' && jeab_ordrar_looks_like_street($street)) {
        $query = $street;
    } elseif (jeab_ordrar_looks_like_street($raw)) {
        $query = $raw;
    }
    return jeab_ordrar_lookup_dedupe_place(jeab_ordrar_lookup_free_text($query));
}

/** «Sollentuna Sollentuna» blir en ort. Upprepad ort i början eller slutet tas bort. */
function jeab_ordrar_lookup_dedupe_place(string $query): string
{
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    if ($query === '') {
        return '';
    }
    $parts = preg_split('/\s+/u', $query) ?: [];
    $fold = static function (string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    };
    $changed = true;
    while ($changed && count($parts) >= 2) {
        $changed = false;
        $next = [];
        $i = 0;
        $n = count($parts);
        while ($i < $n) {
            if ($i + 3 < $n
                && $fold($parts[$i] . ' ' . $parts[$i + 1]) === $fold($parts[$i + 2] . ' ' . $parts[$i + 3])) {
                $next[] = $parts[$i];
                $next[] = $parts[$i + 1];
                $i += 4;
                $changed = true;
                continue;
            }
            if ($i + 1 < $n && $fold($parts[$i]) === $fold($parts[$i + 1])) {
                $next[] = $parts[$i];
                $i += 2;
                $changed = true;
                continue;
            }
            $next[] = $parts[$i];
            $i++;
        }
        $parts = $next;
    }

    return trim(implode(' ', $parts));
}

function jeab_ordrar_lookup_free_text(string $query): string
{
    $query = trim(preg_replace('/\s*,\s*/u', ' ', $query) ?? $query);
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
    return $query;
}

function jeab_ordrar_lookup_query(?array $reminder, array $open = []): string
{
    // Gatfrågan läser kontakten när contacts_sole är på. Saknad länk lämnar kopian.
    // Tomt kontaktfält lämnas tomt. Flaggan av är en no-op.
    if ($open !== [] && function_exists('jeab_contacts_sole_paint_order')) {
        $open = jeab_contacts_sole_paint_order($open);
    }
    $customer = jeab_ordrar_person_name($reminder, $open);
    // Orderns egen gata går före leadens adress och titeltext.
    $orderStreet = trim((string) ($open['address'] ?? ''));
    if ($orderStreet === '') {
        $orderStreet = trim((string) ($open['gata'] ?? ''));
    }
    if ($orderStreet === '') {
        $orderStreet = trim((string) ($open['huvudsokande_address'] ?? ''));
    }
    $orderHasStreet = jeab_ordrar_looks_like_street(jeab_ordrar_street_query($orderStreet, $customer, trim((string) ($open['ort'] ?? ''))));
    $orderFirst = $orderHasStreet
        || trim((string) ($open['address_edited_at'] ?? '')) !== ''
        || trim((string) ($open['objekt_saved_at'] ?? '')) !== '';
    $ort = is_array($reminder) && !$orderFirst ? trim((string) ($reminder['ort'] ?? '')) : '';
    if ($ort === '') {
        $ort = trim((string) ($open['ort'] ?? ''));
    }
    $raws = [];
    $raws[] = trim((string) ($open['address'] ?? ''));
    $raws[] = trim((string) ($open['gata'] ?? ''));
    $raws[] = trim((string) ($open['huvudsokande_address'] ?? ''));
    if (is_array($reminder)) {
        $raws[] = trim((string) ($reminder['address'] ?? ''));
        if (function_exists('jeab_reminder_address_source')) {
            $raws[] = jeab_reminder_address_source($reminder);
        }
        $raws[] = trim((string) ($reminder['title'] ?? ''));
    }
    foreach ($raws as $raw) {
        $clean = jeab_ordrar_street_query($raw, $customer, $ort);
        if (jeab_ordrar_looks_like_street($clean)) {
            return jeab_ordrar_lookup_dedupe_place($clean);
        }
    }
    return '';
}

function jeab_ordrar_format_display_address(string $address): string
{
    if (function_exists('jeab_rot_format_display_address')) {
        return jeab_rot_format_display_address($address);
    }
    $address = trim(preg_replace('/\s+/', ' ', $address) ?? $address);
    return $address;
}

function jeab_ordrar_format_beteckning(string $fast): string
{
    if (function_exists('jeab_rot_format_beteckning')) {
        return jeab_rot_format_beteckning($fast);
    }
    $fast = trim($fast);
    if ($fast === '') {
        return '';
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($fast, 'UTF-8') : strtoupper($fast);
}

/** @return array{fastighetsbeteckning:string,address:string,owners:array,postnummer:string,ort:string} */
function jeab_ordrar_lookup_empty(): array
{
    return [
        'fastighetsbeteckning' => '',
        'address' => '',
        'owners' => [],
        'postnummer' => '',
        'ort' => '',
    ];
}

function jeab_ordrar_address_lookup(string $address, string $objektId = ''): array
{
    $empty = jeab_ordrar_lookup_empty();
    $address = trim($address);
    $objektId = trim($objektId);
    if ($address === '' || jeab_ordrar_looks_like_beteckning($address)) {
        return $empty;
    }
    if (!jeab_ordrar_external_live() && empty($GLOBALS['_JEAB_BEKRAFTA_LOOKUP'])) {
        return $empty;
    }
    static $cache = [];
    $file = JEAB_ROOT . '/src/address_lookup.php';
    if (!is_file($file)) {
        $file = JEAB_ROOT . '/rot/address_lookup.php';
    }
    if (!is_file($file)) {
        return $empty;
    }
    try {
        require_once $file;
    } catch (Throwable $e) {
        if (function_exists('jeab_rot_lookup_log')) {
            jeab_rot_lookup_log('fel vid uppslag', get_class($e) . ': ' . $e->getMessage());
        }
        return $empty;
    }
    if (!function_exists('jeab_rot_lookup_address')) {
        return $empty;
    }
    $gen = (int) ($GLOBALS['_JEAB_LM_CACHE_GEN'] ?? 0);
    $propertyKey = function_exists('jeab_rot_lm_cache_key')
        ? jeab_rot_lm_cache_key($address, $objektId)
        : $address . "\n" . $objektId;
    $cacheKey = $gen . "\0" . $propertyKey;
    $bypass = !empty($GLOBALS['_JEAB_LM_CACHE_BYPASS']);
    if (!$bypass && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    if (function_exists('jeab_rot_lm_cache_get')) {
        $hit = jeab_rot_lm_cache_get($address, $objektId);
        if (is_array($hit)) {
            $mapped = jeab_ordrar_lookup_from_rot($hit);
            $cache[$cacheKey] = $mapped;
            return $mapped;
        }
    }
    try {
        $found = jeab_rot_lookup_address($address, $objektId);
    } catch (Throwable $e) {
        if (function_exists('jeab_rot_lookup_log')) {
            jeab_rot_lookup_log('fel vid uppslag', get_class($e) . ': ' . $e->getMessage());
        }
        return $empty;
    }
    if (!is_array($found)) {
        return $empty;
    }
    $mapped = jeab_ordrar_lookup_from_rot($found);
    if (trim((string) ($found['objektidentitet'] ?? '')) !== '') {
        $mapped['objektidentitet'] = trim((string) $found['objektidentitet']);
    }
    if (function_exists('jeab_rot_lm_cache_put') && trim((string) ($mapped['fastighetsbeteckning'] ?? '')) !== '') {
        $missingPnr = false;
        foreach (is_array($mapped['owners'] ?? null) ? $mapped['owners'] : [] as $owner) {
            if (is_array($owner) && trim((string) ($owner['name'] ?? '')) !== '' && trim((string) ($owner['personnummer'] ?? '')) === '') {
                $missingPnr = true;
                break;
            }
        }
        jeab_rot_lm_cache_put($address, $mapped, $missingPnr, $objektId);
    }
    if (!$bypass) {
        $cache[$cacheKey] = $mapped;
    }
    return $mapped;
}

function jeab_ordrar_lookup_from_rot(array $found): array
{
    $owners = [];
    foreach (is_array($found['owners'] ?? null) ? $found['owners'] : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? ''));
        $pnr = trim((string) ($item['personnummer'] ?? ''));
        if ($name === '' && $pnr === '') {
            continue;
        }
        $owners[] = jeab_ordrar_person($item);
    }
    $display = jeab_ordrar_format_display_address(trim((string) ($found['address'] ?? '')));
    if ($display === '') {
        foreach ($owners as $owner) {
            $display = jeab_ordrar_format_display_address(trim((string) ($owner['address'] ?? '')));
            if ($display !== '') {
                break;
            }
        }
    }
    $zip = jeab_ordrar_postnummer_digits((string) ($found['postnummer'] ?? ''));
    $ort = trim((string) ($found['ort'] ?? ''));
    if (($zip === '' || $ort === '') && $display !== '' && function_exists('jeab_ellevio_split_address')) {
        $parts = jeab_ellevio_split_address($display);
        if ($zip === '') {
            $zip = jeab_ordrar_postnummer_digits((string) ($parts['postnummer'] ?? ''));
        }
        if ($ort === '') {
            $ort = trim((string) ($parts['ort'] ?? ''));
        }
    }
    $choices = [];
    foreach (is_array($found['choices'] ?? null) ? $found['choices'] : [] as $choice) {
        if (!is_array($choice)) {
            continue;
        }
        $label = trim((string) ($choice['address'] ?? ''));
        if ($label === '') {
            continue;
        }
        $choices[] = [
            'id' => trim((string) ($choice['id'] ?? '')),
            'address' => $label,
        ];
    }
    $fetched = trim((string) ($found['fetched_address'] ?? ''));
    $exact = !array_key_exists('lookup_exact', $found) || !empty($found['lookup_exact']);
    if (!$exact) {
        return [
            'fastighetsbeteckning' => '',
            'address' => '',
            'owners' => [],
            'postnummer' => '',
            'ort' => '',
            'fetched_address' => $fetched,
            'lookup_exact' => false,
            'choices' => $choices,
        ];
    }
    return [
        'fastighetsbeteckning' => jeab_ordrar_format_beteckning((string) ($found['fastighetsbeteckning'] ?? '')),
        'address' => $display,
        'owners' => $owners,
        'postnummer' => $zip,
        'ort' => $ort,
        'fetched_address' => $fetched !== '' ? $fetched : $display,
        'lookup_exact' => true,
        'choices' => [],
    ];
}

function jeab_ordrar_name_contains(string $haystack, string $needle): bool
{
    $haystack = trim($haystack);
    $needle = trim($needle);
    if ($haystack === '' || $needle === '') {
        return false;
    }
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return stripos($haystack, $needle) !== false;
}

function jeab_ordrar_same_person(string $a, string $b): bool
{
    $a = trim($a);
    $b = trim($b);
    if ($a === '' || $b === '') {
        return false;
    }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8');
    }
    return strcasecmp($a, $b) === 0;
}

function jeab_ordrar_find_owner(array $owners, string $name, string $pnr = ''): array
{
    $name = trim($name);
    $pnr = jeab_ordrar_format_pnr($pnr);
    $empty = jeab_ordrar_person([]);
    foreach ($owners as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $person = jeab_ordrar_person($owner);
        if ($pnr !== '' && $person['personnummer'] !== '' && $person['personnummer'] === $pnr) {
            return $person;
        }
    }
    foreach ($owners as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $person = jeab_ordrar_person($owner);
        if ($name !== '' && jeab_ordrar_same_person($name, $person['name'])) {
            return $person;
        }
    }
    return jeab_ordrar_fuzzy_owner($owners, $name);
}

/** Gemener utan diakritiska tecken (å→a, ö→o, é→e …) för namnjämförelse. */
function jeab_ordrar_name_fold(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $map = [
        'å' => 'a', 'ä' => 'a', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ā' => 'a',
        'ö' => 'o', 'ø' => 'o', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ō' => 'o',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
        'ü' => 'u', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ū' => 'u',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ç' => 'c', 'č' => 'c', 'ć' => 'c', 'ñ' => 'n', 'ń' => 'n',
        'š' => 's', 'ś' => 's', 'ş' => 's', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
        'ý' => 'y', 'ÿ' => 'y', 'æ' => 'ae', 'ß' => 'ss', 'ł' => 'l', 'ğ' => 'g', 'ı' => 'i',
    ];
    return strtr($name, $map);
}

/** @return list<string> */
function jeab_ordrar_name_parts(string $name): array
{
    $fold = jeab_ordrar_name_fold($name);
    if ($fold === '') {
        return [];
    }
    $parts = preg_split('/[\s,]+/u', $fold) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn(string $p): bool => $p !== ''));
}

/**
 * Lagfartsägare passar kunden: samma efternamn OCH kundens förnamn finns
 * bland ägarens förnamn. Skiftläge och diakritiska tecken spelar ingen roll.
 */
function jeab_ordrar_owner_fits_customer(string $ownerName, string $customerName): bool
{
    $owner = jeab_ordrar_name_parts($ownerName);
    $customer = jeab_ordrar_name_parts($customerName);
    if (count($owner) < 2 || count($customer) < 2) {
        return false;
    }
    if ($owner[count($owner) - 1] !== $customer[count($customer) - 1]) {
        return false;
    }
    $first = $customer[0];
    $given = [];
    foreach (array_slice($owner, 0, -1) as $part) {
        $given[] = $part;
        foreach (explode('-', $part) as $sub) {
            if ($sub !== '') {
                $given[] = $sub;
            }
        }
    }
    return in_array($first, $given, true);
}

function jeab_ordrar_same_surname(string $a, string $b): bool
{
    $pa = jeab_ordrar_name_parts($a);
    $pb = jeab_ordrar_name_parts($b);
    if (count($pa) < 2 || count($pb) < 2) {
        return false;
    }
    return $pa[count($pa) - 1] === $pb[count($pb) - 1];
}

/**
 * Försiktig reserv när exakt namnmatchning saknas.
 * 1) Exakt en ägare med kundens efternamn och kundens förnamn bland förnamnen.
 * 2) Annars: fastigheten har exakt en ägare och den har kundens efternamn.
 * Ägare med annat efternamn väljs aldrig. Flera kandidater ger ingen träff.
 */
function jeab_ordrar_fuzzy_owner(array $owners, string $customerName): array
{
    $empty = jeab_ordrar_person([]);
    $customerName = trim($customerName);
    if ($customerName === '') {
        return $empty;
    }
    $people = [];
    foreach ($owners as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $person = jeab_ordrar_person($owner);
        if ($person['name'] === '') {
            continue;
        }
        $people[] = $person;
    }
    $fits = [];
    foreach ($people as $person) {
        if (jeab_ordrar_owner_fits_customer($person['name'], $customerName)) {
            $fits[] = $person;
        }
    }
    if (count($fits) === 1) {
        return $fits[0];
    }
    if (count($fits) > 1) {
        return $empty;
    }
    if (count($people) === 1 && jeab_ordrar_same_surname($people[0]['name'], $customerName)) {
        return $people[0];
    }
    return $empty;
}

function jeab_ordrar_match_owner(array $owners, string $customerName): array
{
    $first = jeab_ordrar_first_name($customerName);
    $empty = jeab_ordrar_person([]);
    if ($first === '') {
        return $empty;
    }
    foreach ($owners as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $person = jeab_ordrar_person($owner);
        $name = $person['name'];
        if ($name === '') {
            continue;
        }
        if (jeab_ordrar_same_person(jeab_ordrar_first_name($name), $first)) {
            return $person;
        }
    }
    return jeab_ordrar_fuzzy_owner($owners, $customerName);
}

function jeab_ordrar_person(mixed $raw): array
{
    if (!is_array($raw)) {
        return ['name' => trim((string) $raw), 'personnummer' => ''];
    }
    return [
        'name' => trim((string) ($raw['name'] ?? $raw['namn'] ?? '')),
        'personnummer' => jeab_ordrar_format_pnr(trim((string) ($raw['personnummer'] ?? $raw['pnr'] ?? $raw['ssn'] ?? ''))),
        'address' => trim((string) ($raw['address'] ?? $raw['adress'] ?? '')),
        'andel' => trim((string) ($raw['andel'] ?? '')),
        'lagfart' => trim((string) ($raw['lagfart'] ?? $raw['lagfart_datum'] ?? $raw['inskrivningsdag'] ?? '')),
    ];
}

function jeab_ordrar_people_list(mixed $raw, int $max = 3): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $item) {
        if (count($out) >= $max) {
            break;
        }
        if (is_string($item) || is_numeric($item)) {
            $item = ['name' => (string) $item, 'personnummer' => ''];
        }
        if (!is_array($item)) {
            continue;
        }
        $person = jeab_ordrar_person($item);
        if ($person['name'] === '' && $person['personnummer'] === '') {
            continue;
        }
        $out[] = $person;
    }
    return $out;
}

function jeab_ordrar_people_from_named_input(array $input, string $nameKey, string $pnrKey, string $addrKey, int $max = 3): array
{
    $names = is_array($input[$nameKey] ?? null) ? $input[$nameKey] : [];
    $pnrs = is_array($input[$pnrKey] ?? null) ? $input[$pnrKey] : [];
    $addrs = is_array($input[$addrKey] ?? null) ? $input[$addrKey] : [];
    $out = [];
    $n = max(count($names), count($pnrs), count($addrs));
    for ($i = 0; $i < min($max, $n); $i++) {
        $person = jeab_ordrar_person([
            'name' => $names[$i] ?? '',
            'personnummer' => $pnrs[$i] ?? '',
            'address' => $addrs[$i] ?? '',
        ]);
        if ($person['name'] === '' && $person['personnummer'] === '') {
            continue;
        }
        $out[] = $person;
    }
    return $out;
}

function jeab_ordrar_people_from_input(array $input, string $nameKey, string $pnrKey, int $max = 3): array
{
    $names = is_array($input[$nameKey] ?? null) ? $input[$nameKey] : [];
    $pnrs = is_array($input[$pnrKey] ?? null) ? $input[$pnrKey] : [];
    $addrs = is_array($input['medsokande_address'] ?? null) ? $input['medsokande_address'] : [];
    $andel = is_array($input['medsokande_andel'] ?? null) ? $input['medsokande_andel'] : [];
    $lagfart = is_array($input['medsokande_lagfart'] ?? null) ? $input['medsokande_lagfart'] : [];
    $out = [];
    $n = max(count($names), count($pnrs), count($addrs), count($andel), count($lagfart));
    for ($i = 0; $i < min($max, $n); $i++) {
        $person = jeab_ordrar_person([
            'name' => $names[$i] ?? '',
            'personnummer' => $pnrs[$i] ?? '',
            'address' => $addrs[$i] ?? '',
            'andel' => $andel[$i] ?? '',
            'lagfart' => $lagfart[$i] ?? '',
        ]);
        if ($person['name'] === '' && $person['personnummer'] === '') {
            continue;
        }
        $out[] = $person;
    }
    return $out;
}

function jeab_ordrar_people_pad(array $people, int $max = 3): array
{
    $people = array_slice(jeab_ordrar_people_list($people, $max), 0, $max);
    while (count($people) < $max) {
        $people[] = ['name' => '', 'personnummer' => '', 'address' => '', 'andel' => '', 'lagfart' => ''];
    }
    return $people;
}

function jeab_ordrar_other_owners(array $owners, string $huvudsokande): array
{
    $skip = trim($huvudsokande);
    $out = [];
    foreach ($owners as $owner) {
        if (count($out) >= 8) {
            break;
        }
        $person = jeab_ordrar_person($owner);
        if ($person['name'] === '' && $person['personnummer'] === '') {
            continue;
        }
        if ($skip !== '' && jeab_ordrar_person_matches(['name' => $skip, 'personnummer' => ''], $person)) {
            continue;
        }
        $dup = false;
        foreach ($out as $have) {
            if (jeab_ordrar_person_matches($have, $person)) {
                $dup = true;
                break;
            }
        }
        if ($dup) {
            continue;
        }
        $out[] = $person;
    }
    return $out;
}

/** Frågan plus orderns postnummer när det finns, så 6 i ett annat postnummer inte räknas. */
function jeab_ordrar_lookup_customer_address(string $query, array $open): string
{
    $query = trim($query);
    $postal = jeab_ordrar_postnummer_digits((string) ($open['postnummer'] ?? $open['postnr'] ?? ''));
    if ($postal === '' && function_exists('jeab_rot_postnummer_digits')) {
        $postal = jeab_rot_postnummer_digits((string) ($open['address'] ?? ''));
    }
    if ($postal !== '' && function_exists('jeab_rot_postnummer_digits') && jeab_rot_postnummer_digits($query) === '') {
        $query = trim($query . ' ' . substr($postal, 0, 3) . ' ' . substr($postal, 3));
    }
    return $query;
}

/**
 * Prefix-träff (6 mot 66 eller 6A, eller annat postnummer) blir tom.
 * En rad utan husnummer lämnas, den säger inte emot.
 *
 * @param array<string, mixed> $lookup
 * @return array<string, mixed>
 */
function jeab_ordrar_lookup_reject_prefix(array $lookup, string $query): array
{
    if (!function_exists('jeab_rot_address_exact') || !function_exists('jeab_rot_address_identity')) {
        return $lookup;
    }
    if (jeab_rot_address_identity($query)['number'] === '') {
        return $lookup;
    }
    $have = trim((string) ($lookup['address'] ?? ''));
    if ($have === '') {
        $have = trim((string) ($lookup['fetched_address'] ?? ''));
    }
    if ($have === '' || jeab_rot_address_identity($have)['number'] === '') {
        return $lookup;
    }
    if (jeab_rot_address_exact($query, $have)) {
        return $lookup;
    }
    $lookup['fastighetsbeteckning'] = '';
    $lookup['owners'] = [];
    $lookup['address'] = '';
    $lookup['postnummer'] = '';
    $lookup['ort'] = '';
    $lookup['personnummer'] = '';
    $lookup['lookup_exact'] = false;
    return $lookup;
}

/** Sparad fastighetsbeteckning från Objekt (manuellt), inte ett automatiskt uppslag. */
function jeab_ordrar_explicit_beteckning(array $open): string
{
    $fast = trim((string) ($open['fastighetsbeteckning'] ?? ''));
    if ($fast === '') {
        return '';
    }
    $mark = is_array($open['identity_source']['fastighetsbeteckning'] ?? null)
        ? $open['identity_source']['fastighetsbeteckning']
        : [];
    if ((string) ($mark['src'] ?? '') !== 'manual') {
        return '';
    }
    $marked = trim((string) ($mark['value'] ?? ''));
    $now = jeab_ordrar_identity_value($open, 'fastighetsbeteckning');
    if ($marked === '' || $marked !== $now) {
        return '';
    }

    return $fast;
}

function jeab_ordrar_beteckning_same(string $a, string $b): bool
{
    $fold = static function (string $value): string {
        $value = jeab_ordrar_format_beteckning($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    };

    return $fold($a) !== '' && $fold($a) === $fold($b);
}

/**
 * Lantmäteriet söks på adressen (och objekt-id när det finns).
 * Träffen är raden vars beteckning är densamma. Annars tomt.
 *
 * @return array{fastighetsbeteckning:string,address:string,owners:array,postnummer:string,ort:string}
 */
function jeab_ordrar_beteckning_lookup(string $beteckning, string $address = '', string $objektId = ''): array
{
    $empty = jeab_ordrar_lookup_empty();
    $beteckning = trim($beteckning);
    $address = trim($address);
    $objektId = trim($objektId);
    if ($beteckning === '' || $address === '' || !jeab_ordrar_external_live()) {
        return $empty;
    }
    $file = JEAB_ROOT . '/src/address_lookup.php';
    if (!is_file($file)) {
        $file = JEAB_ROOT . '/rot/address_lookup.php';
    }
    if (!is_file($file)) {
        return $empty;
    }
    try {
        require_once $file;
    } catch (Throwable $e) {
        if (function_exists('jeab_rot_lookup_log')) {
            jeab_rot_lookup_log('fel vid uppslag på beteckning', get_class($e));
        }

        return $empty;
    }
    if (!function_exists('jeab_lm_lookup_property')) {
        return $empty;
    }
    $prepared = function_exists('jeab_rot_lookup_query') ? jeab_rot_lookup_query($address) : $address;
    if ($prepared === '' || preg_match('/\d+\s*:\s*\d+/', $prepared) === 1 || jeab_ordrar_looks_like_beteckning($prepared)) {
        return $empty;
    }
    // Samma sökning och samma ägaruttag som Manuell order. Sparat objekt-id
    // ändrar svaret (lagfart mot tomträtt) och skickas därför inte med.
    $objektId = '';
    try {
        $found = jeab_lm_lookup_property($address, '', $beteckning);
    } catch (Throwable $e) {
        if (function_exists('jeab_rot_lookup_log')) {
            jeab_rot_lookup_log('fel vid uppslag på beteckning', get_class($e));
        }

        return $empty;
    }
    if (!is_array($found)) {
        return $empty;
    }
    $got = trim((string) ($found['fastighetsbeteckning'] ?? ''));
    if ($got === '' || !jeab_ordrar_beteckning_same($beteckning, $got)) {
        return $empty;
    }
    $found['lookup_exact'] = true;

    return jeab_ordrar_lookup_from_rot($found);
}

function jeab_ordrar_confirm_suggest(array $open, ?array $reminder, ?string $query = null, string $objektId = '', bool $fresh = false): array
{
    $fresh = $fresh || !empty($GLOBALS['_JEAB_LM_FRESH']);
    $explicit = jeab_ordrar_explicit_beteckning($open);
    $objektId = trim($objektId);
    if ($objektId === '' && !$fresh) {
        $objektId = trim((string) ($open['objektidentitet'] ?? ''));
    }
    $address = $query !== null ? jeab_ordrar_lookup_free_text($query) : jeab_ordrar_lookup_query($reminder, $open);
    $byFast = null;
    $explicitMiss = false;
    if ($explicit !== '') {
        $try = jeab_ordrar_beteckning_lookup($explicit, $address, $objektId);
        $gotFast = trim((string) ($try['fastighetsbeteckning'] ?? ''));
        if ($gotFast !== '' && jeab_ordrar_beteckning_same($explicit, $gotFast)) {
            $byFast = $try;
        } else {
            $explicitMiss = true;
        }
    }
    $customer = jeab_ordrar_person_name($reminder, $open);
    $title = is_array($reminder) ? trim((string) ($reminder['title'] ?? '')) : '';
    $placeholder = jeab_ordrar_first_name($customer);
    if (jeab_ordrar_is_title_or_product_word($placeholder, $title)) {
        $placeholder = '';
    }
    $empty = [
        'address' => $address,
        'personnummer' => '',
        'fastighetsbeteckning' => '',
        'huvudsokande' => '',
        'huvudsokande_placeholder' => $placeholder,
        'huvudsokande_address' => '',
        'huvudsokande_andel' => '',
        'huvudsokande_lagfart' => '',
        'medsokande' => [],
        'name_matched' => false,
        'match_first' => $placeholder,
        'lookup_empty' => false,
        'postnummer' => '',
        'ort' => '',
    ];
    if (is_array($byFast)) {
        $lookup = $byFast;
    } elseif ($explicitMiss && $fresh) {
        // Omhämtning som ber om en beteckning tar inte en annan fastighet på adressen.
        $lookup = jeab_ordrar_lookup_empty();
        $lookup['lookup_exact'] = false;
    } else {
        $lookup = jeab_ordrar_address_lookup($address, trim($objektId));
        $lookup = jeab_ordrar_lookup_reject_prefix($lookup, jeab_ordrar_lookup_customer_address($address, $open));
    }
    $fast = jeab_ordrar_format_beteckning((string) ($lookup['fastighetsbeteckning'] ?? ''));
    $owners = is_array($lookup['owners'] ?? null) ? $lookup['owners'] : [];
    $display = jeab_ordrar_format_display_address(trim((string) ($lookup['address'] ?? '')));
    $lookupZip = jeab_ordrar_postnummer_digits((string) ($lookup['postnummer'] ?? ''));
    $lookupOrt = trim((string) ($lookup['ort'] ?? ''));
    if (array_key_exists('lookup_exact', $lookup) && empty($lookup['lookup_exact'])) {
        $fast = '';
        $owners = [];
        $display = '';
        $lookupZip = '';
        $lookupOrt = '';
    }
    if ($fast === '' && $owners === []) {
        if ($address !== '') {
            $empty['huvudsokande_address'] = $address;
            $empty['address'] = $address;
        }
        $empty['lookup_empty'] = true;
        $overlaid = jeab_ordrar_suggest_overlay_purchase($empty, $open);
        $overlaid['lookup_empty'] = true;
        $first = jeab_ordrar_first_name($customer);
        $savedOnRow = trim((string) ($open['huvudsokande'] ?? ''));
        if ($savedOnRow === ''
            && !jeab_ordrar_honor_huvudsokande_pick($open, $first)
            && trim((string) ($overlaid['personnummer'] ?? '')) === ''
            && trim((string) ($overlaid['fastighetsbeteckning'] ?? '')) === '') {
            $savedHuvud = trim((string) ($overlaid['huvudsokande'] ?? ''));
            if ($savedHuvud === ''
                || ($customer !== '' && (
                    jeab_ordrar_same_person($savedHuvud, $customer)
                    || ($first !== '' && jeab_ordrar_name_contains($savedHuvud, $first))
                ))) {
                $overlaid['huvudsokande'] = '';
            }
        }
        $savedOwners = jeab_ordrar_people_list($open['owners'] ?? [], 8);
        $overlaid['owners'] = jeab_ordrar_suggest_owners($overlaid, $savedOwners);
        $overlaid['postnummer'] = $lookupZip;
        $overlaid['ort'] = $lookupOrt;
        return jeab_ordrar_attach_lookup_meta($overlaid, $lookup);
    }
    $picked = [];
    $nameMatched = false;
    $first = jeab_ordrar_first_name($customer);
    $honorPick = !$fresh && jeab_ordrar_honor_huvudsokande_pick($open, $first);
    if ($honorPick) {
        $picked = jeab_ordrar_find_owner(
            $owners,
            (string) ($open['huvudsokande'] ?? ''),
            (string) ($open['personnummer'] ?? '')
        );
        if (trim((string) ($picked['name'] ?? '')) === '') {
            $picked = jeab_ordrar_person([
                'name' => (string) ($open['huvudsokande'] ?? ''),
                'personnummer' => (string) ($open['personnummer'] ?? ''),
                'address' => (string) ($open['huvudsokande_address'] ?? ''),
            ]);
        }
    }
    if (!$honorPick && trim((string) ($picked['name'] ?? '')) === '') {
        $picked = jeab_ordrar_match_owner($owners, $customer);
        $nameMatched = trim((string) ($picked['name'] ?? '')) !== '';
    }
    if (!$fresh && trim((string) ($picked['name'] ?? '')) === '' && $first === '') {
        $savedHuvud = trim((string) ($open['huvudsokande'] ?? ''));
        if ($savedHuvud !== '') {
            $picked = jeab_ordrar_find_owner($owners, $savedHuvud, (string) ($open['personnummer'] ?? ''));
            if (trim((string) ($picked['name'] ?? '')) === '') {
                $picked = jeab_ordrar_person([
                    'name' => $savedHuvud,
                    'personnummer' => (string) ($open['personnummer'] ?? ''),
                    'address' => (string) ($open['huvudsokande_address'] ?? ''),
                ]);
            }
        }
    }
    if ($fresh && trim((string) ($picked['name'] ?? '')) === '' && $owners !== []) {
        $picked = jeab_ordrar_person($owners[0]);
    }
    $huvud = trim((string) ($picked['name'] ?? ''));
    $huvudAddr = jeab_ordrar_format_display_address(trim((string) ($picked['address'] ?? '')));
    if ($huvudAddr === '') {
        $huvudAddr = $display;
    }
    $med = jeab_ordrar_other_owners($owners, $huvud);
    $pickedPnr = preg_replace('/\D+/', '', (string) ($picked['personnummer'] ?? '')) ?? '';
    if ($pickedPnr !== '') {
        // Huvudsökande står inte också som medsökande (annan stavning, samma person).
        $med = array_values(array_filter($med, static function (array $person) use ($pickedPnr): bool {
            return (preg_replace('/\D+/', '', (string) ($person['personnummer'] ?? '')) ?? '') !== $pickedPnr;
        }));
    }
    foreach ($med as $i => $person) {
        $medAddr = jeab_ordrar_format_display_address(trim((string) ($person['address'] ?? '')));
        $med[$i]['address'] = $medAddr !== '' ? $medAddr : $huvudAddr;
        $med[$i]['personnummer'] = jeab_ordrar_format_pnr((string) ($person['personnummer'] ?? ''));
    }
    $suggest = [
        'address' => $huvudAddr !== '' ? $huvudAddr : $address,
        'personnummer' => jeab_ordrar_format_pnr((string) ($picked['personnummer'] ?? '')),
        'fastighetsbeteckning' => $fast,
        'huvudsokande' => $huvud,
        'huvudsokande_placeholder' => $placeholder,
        'huvudsokande_address' => $huvudAddr,
        'huvudsokande_andel' => '',
        'huvudsokande_lagfart' => '',
        'medsokande' => $med,
        'name_matched' => $nameMatched,
        'match_first' => $first,
        'lookup_empty' => false,
        'postnummer' => $lookupZip,
        'ort' => $lookupOrt,
    ];
    $suggest['owners'] = jeab_ordrar_suggest_owners($suggest, $owners);
    return jeab_ordrar_attach_lookup_meta($suggest, $lookup);
}

/** Adress som faktiskt kom tillbaka, och alternativ när inget valdes. */
function jeab_ordrar_attach_lookup_meta(array $suggest, array $lookup): array
{
    $suggest['fetched_address'] = trim((string) ($lookup['fetched_address'] ?? ''));
    $suggest['lookup_exact'] = !empty($lookup['lookup_exact']);
    $choices = [];
    foreach (is_array($lookup['choices'] ?? null) ? $lookup['choices'] : [] as $choice) {
        if (!is_array($choice)) {
            continue;
        }
        $label = trim((string) ($choice['address'] ?? ''));
        if ($label === '') {
            continue;
        }
        $choices[] = [
            'id' => trim((string) ($choice['id'] ?? '')),
            'address' => $label,
        ];
    }
    $suggest['choices'] = $choices;
    return $suggest;
}

function jeab_ordrar_suggest_owners(array $suggest, array $lookupOwners): array
{
    $huvudAddr = jeab_ordrar_format_display_address(trim((string) ($suggest['huvudsokande_address'] ?? $suggest['address'] ?? '')));
    $out = [];
    foreach ($lookupOwners as $owner) {
        $person = jeab_ordrar_person($owner);
        if ($person['name'] === '' && $person['personnummer'] === '') {
            continue;
        }
        $addr = jeab_ordrar_format_display_address(trim((string) ($person['address'] ?? '')));
        $person['address'] = $addr !== '' ? $addr : $huvudAddr;
        $person['personnummer'] = jeab_ordrar_format_pnr((string) ($person['personnummer'] ?? ''));
        $out[] = $person;
    }
    if ($out !== []) {
        return $out;
    }
    $huvud = trim((string) ($suggest['huvudsokande'] ?? ''));
    $pnr = jeab_ordrar_format_pnr((string) ($suggest['personnummer'] ?? ''));
    if ($huvud !== '' || $pnr !== '') {
        $out[] = [
            'name' => $huvud,
            'personnummer' => $pnr,
            'address' => $huvudAddr,
            'andel' => '',
            'lagfart' => '',
        ];
    }
    foreach (jeab_ordrar_people_list($suggest['medsokande'] ?? []) as $med) {
        $out[] = $med;
    }
    return $out;
}

function jeab_ordrar_owner_cards(array $suggest): array
{
    $huvudName = trim((string) ($suggest['huvudsokande'] ?? ''));
    $huvudPnr = jeab_ordrar_format_pnr((string) ($suggest['personnummer'] ?? ''));
    $huvudAddr = trim((string) ($suggest['huvudsokande_address'] ?? $suggest['address'] ?? ''));
    $listed = jeab_ordrar_suggest_owners($suggest, is_array($suggest['owners'] ?? null) ? $suggest['owners'] : []);
    $cards = [];
    $haveHuvud = false;
    foreach ($listed as $person) {
        $name = trim((string) ($person['name'] ?? ''));
        $pnr = jeab_ordrar_format_pnr((string) ($person['personnummer'] ?? ''));
        $isHuvud = !$haveHuvud && jeab_ordrar_person_matches(
            ['name' => $huvudName, 'personnummer' => $huvudPnr],
            ['name' => $name, 'personnummer' => $pnr]
        );
        if ($isHuvud) {
            $haveHuvud = true;
        }
        $addr = trim((string) ($person['address'] ?? ''));
        $cards[] = [
            'name' => $name,
            'personnummer' => $pnr,
            'address' => $addr !== '' ? $addr : $huvudAddr,
            'huvud' => $isHuvud,
        ];
    }
    if (!$haveHuvud && $huvudName !== '') {
        array_unshift($cards, [
            'name' => $huvudName,
            'personnummer' => $huvudPnr,
            'address' => $huvudAddr,
            'huvud' => true,
        ]);
        $haveHuvud = true;
    }
    if (!$haveHuvud && $cards !== []) {
        $cards[0]['huvud'] = true;
    }
    if ($cards === []) {
        $cards[] = [
            'name' => $huvudName,
            'personnummer' => $huvudPnr,
            'address' => $huvudAddr,
            'huvud' => true,
        ];
    }
    return $cards;
}

function jeab_ordrar_owner_pills_html(bool $isHuvud): string
{
    $role = $isHuvud ? 'huvudsokande' : 'medsokande';
    $label = $isHuvud ? 'Huvudsökande' : 'Medsökande';
    $sel = $isHuvud ? ' is-selected' : '';
    return '<span class="ordrar-owner-pills" role="group" aria-label="Roll">'
        . '<button type="button" class="ordrar-owner-pill' . $sel . '" data-role="' . $role . '" aria-pressed="'
        . ($isHuvud ? 'true' : 'false') . '">' . $label . '</button>'
        . '</span>';
}

function jeab_ordrar_suggest_overlay_purchase(array $suggest, array $open): array
{
    if (trim((string) ($suggest['huvudsokande'] ?? '')) === '') {
        $suggest['huvudsokande'] = trim((string) ($open['huvudsokande'] ?? ''));
    }
    if (trim((string) ($suggest['personnummer'] ?? '')) === '') {
        $suggest['personnummer'] = jeab_ordrar_format_pnr((string) ($open['personnummer'] ?? ''));
    }
    if (trim((string) ($suggest['fastighetsbeteckning'] ?? '')) === '') {
        $suggest['fastighetsbeteckning'] = jeab_ordrar_format_beteckning((string) ($open['fastighetsbeteckning'] ?? ''));
    }
    $addr = trim((string) ($suggest['huvudsokande_address'] ?? ''));
    if ($addr === '') {
        $addr = trim((string) ($open['huvudsokande_address'] ?? $open['address'] ?? ''));
        $suggest['huvudsokande_address'] = $addr;
    }
    if (trim((string) ($suggest['address'] ?? '')) === '' && $addr !== '') {
        $suggest['address'] = $addr;
    }
    $med = is_array($suggest['medsokande'] ?? null) ? $suggest['medsokande'] : [];
    if ($med === []) {
        $suggest['medsokande'] = jeab_ordrar_people_list($open['medsokande'] ?? []);
    }
    return $suggest;
}

function jeab_ordrar_confirm_cell(string $name, string $value, bool $edit, string $placeholder = '', bool $detectOff = false): string
{
    $detect = $detectOff ? ' x-apple-data-detectors="false"' : '';
    if (!$edit) {
        return '<span class="ordrar-owner-val"' . $detect . '>' . e($value) . '</span>'
            . '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
    }
    return '<input type="text" class="ordrar-owner-val" name="' . e($name) . '" value="' . e($value)
        . '" placeholder="' . e($placeholder) . '" autocomplete="off"' . $detect . '>';
}

function jeab_ordrar_owner_value_html(string $value, bool $edit, string $field, string $placeholder = '', bool $detectOff = false): string
{
    $detect = $detectOff ? ' x-apple-data-detectors="false"' : '';
    $fieldAttr = $field !== '' ? ' data-field="' . e($field) . '"' : '';
    if (!$edit) {
        return '<span class="ordrar-owner-val"' . $detect . $fieldAttr . '>' . e($value) . '</span>';
    }
    return '<input type="text" class="ordrar-owner-val" value="' . e($value)
        . '" placeholder="' . e($placeholder) . '" autocomplete="off"' . $detect . $fieldAttr . '>';
}

function jeab_ordrar_copy_btn(): string
{
    return '<button type="button" class="ordrar-copy" aria-label="Kopiera">'
        . '<svg class="ordrar-copy-ico" viewBox="0 0 24 24" aria-hidden="true">'
        . '<rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.75"/>'
        . '<rect x="4" y="4" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.75"/>'
        . '</svg></button>';
}

function jeab_ordrar_kr(int $amount): string
{
    return number_format($amount, 0, ',', "\u{202F}");
}

function jeab_ordrar_inkop_qty_stored(mixed $raw): int|string
{
    if (is_int($raw)) {
        return $raw;
    }
    if (is_float($raw)) {
        return (int) $raw;
    }
    $text = trim((string) $raw);
    if ($text === '') {
        return '';
    }
    $text = str_replace(["\u{00A0}", "\u{202F}", ' '], '', $text);
    $text = str_replace(',', '.', $text);
    if (!is_numeric($text)) {
        return '';
    }
    return (int) $text;
}

function jeab_ordrar_inkop_qty_input(mixed $raw): string
{
    $stored = jeab_ordrar_inkop_qty_stored($raw);
    return $stored === '' ? '' : (string) $stored;
}

function jeab_ordrar_inkop_start_rows(): int
{
    return 10;
}

function jeab_ordrar_inkop_more_rows(): int
{
    return 5;
}

function jeab_ordrar_inkop_max_rows(): int
{
    return function_exists('jeab_inkop_line_max') ? jeab_inkop_line_max() : 100;
}

/** En mall med 13 rader visas som 14: alla mallrader och en tom rad under. */
function jeab_ordrar_inkop_mall_row_count(int $lines): int
{
    $count = max(0, $lines) + 1;
    return min(jeab_ordrar_inkop_max_rows(), $count);
}

function jeab_ordrar_inkop_row_used(array $line): bool
{
    if (trim((string) ($line['sku'] ?? $line['artikelnummer'] ?? '')) !== '') {
        return true;
    }
    if (trim((string) ($line['title'] ?? $line['artikelnamn'] ?? '')) !== '') {
        return true;
    }
    return jeab_ordrar_inkop_qty_stored($line['qty'] ?? $line['antal'] ?? '') !== '';
}

function jeab_ordrar_inkop_visible_count(array $lines): int
{
    $last = 0;
    $seen = 0;
    foreach ($lines as $line) {
        if ($seen >= jeab_ordrar_inkop_max_rows()) {
            break;
        }
        if (is_array($line) && jeab_ordrar_inkop_row_used($line)) {
            $last = $seen + 1;
        }
        $seen++;
    }
    return min(jeab_ordrar_inkop_max_rows(), max(jeab_ordrar_inkop_start_rows(), $last));
}

function jeab_ordrar_inkop_pad(array $lines): array
{
    $out = [];
    foreach ($lines as $line) {
        if (count($out) >= jeab_ordrar_inkop_max_rows()) {
            break;
        }
        if (!is_array($line)) {
            continue;
        }
        $out[] = [
            'sku' => trim((string) ($line['sku'] ?? $line['artikelnummer'] ?? '')),
            'title' => trim((string) ($line['title'] ?? $line['artikelnamn'] ?? '')),
            'qty' => jeab_ordrar_inkop_qty_stored($line['qty'] ?? $line['antal'] ?? ''),
            'shop' => jeab_ordrar_normalize_shop((string) ($line['shop'] ?? $line['webshop'] ?? '')),
        ];
    }
    $visible = jeab_ordrar_inkop_visible_count($out);
    while (count($out) < $visible) {
        $out[] = ['sku' => '', 'title' => '', 'qty' => ''];
    }
    return array_slice($out, 0, $visible);
}

function jeab_ordrar_inkop_from_input(array $input): array
{
    $skus = $input['line_sku'] ?? [];
    $titles = $input['line_title'] ?? [];
    $qtys = $input['line_qty'] ?? [];
    $shops = $input['line_shop'] ?? [];
    $skus = is_array($skus) ? $skus : [];
    $titles = is_array($titles) ? $titles : [];
    $qtys = is_array($qtys) ? $qtys : [];
    $shops = is_array($shops) ? $shops : [];
    $lines = [];
    $max = max(count($skus), count($titles), count($qtys), count($shops));
    $limit = min(jeab_ordrar_inkop_max_rows(), $max);
    for ($i = 0; $i < $limit; $i++) {
        $lines[] = [
            'sku' => trim((string) ($skus[$i] ?? '')),
            'title' => trim((string) ($titles[$i] ?? '')),
            'qty' => $qtys[$i] ?? '',
            'shop' => jeab_ordrar_normalize_shop((string) ($shops[$i] ?? '')),
        ];
    }
    return jeab_ordrar_inkop_pad($lines);
}

function jeab_ordrar_require(int $id): array
{
    $row = jeab_purchase_get($id);
    if (!$row) {
        throw new InvalidArgumentException('Ordern finns inte.');
    }
    $company = jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
    if (function_exists('jeab_company_allowed') && !jeab_company_allowed($company)) {
        throw new InvalidArgumentException('Inte behörig.');
    }
    return $row;
}

/** Läser en order för sidan. Annat bolag och saknad rad blir null, utan att byta kontor. */
function jeab_ordrar_visible_get(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        return jeab_ordrar_require($id);
    } catch (InvalidArgumentException) {
        return null;
    }
}

/** Index för huvudsökanden i ägarlistan, eller null när namnet inte finns där. */
function jeab_ordrar_huvud_owner_index(array $owners, string $huvudName): ?int
{
    $huvudName = trim($huvudName);
    if ($huvudName === '') {
        return null;
    }
    foreach ($owners as $i => $person) {
        if (!is_array($person)) {
            continue;
        }
        if (jeab_ordrar_same_person($huvudName, (string) ($person['name'] ?? ''))) {
            return (int) $i;
        }
    }
    return null;
}

/**
 * Huvudägarens personnummer på ordern. Kontaktens slot används inte.
 * Värdet skrivs inte i logg.
 *
 * @param array<string, mixed> $row
 */
function jeab_ordrar_order_owner_pnr(array $row): string
{
    $people = function_exists('jeab_ordrar_sync_lm_people') ? jeab_ordrar_sync_lm_people($row) : [];
    $huvud = trim((string) ($row['huvudsokande'] ?? ''));
    $picked = '';
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $pnr = jeab_ordrar_format_pnr((string) ($person['personnummer'] ?? ''));
        if ($pnr === '') {
            continue;
        }
        if ($picked === '') {
            $picked = $pnr;
        }
        if ($huvud !== '' && jeab_ordrar_person_matches(['name' => $huvud, 'personnummer' => ''], $person)) {
            return $pnr;
        }
    }
    if ($picked !== '') {
        return $picked;
    }

    return jeab_ordrar_format_pnr((string) ($row['personnummer'] ?? ''));
}

/**
 * Personnummer som Bekräfta och Fortnox läser.
 * Med kontakten som källa vinner ägarsloten. En tom slot faller tillbaka på orderns ägare.
 * Utan kontakt används inte orderns kopia här.
 * Annars huvudsökandens ägarkort, och det gamla övre fältet om kortet saknar nummer.
 * Värdet skrivs inte i logg.
 *
 * @param array<string, mixed> $row
 */
function jeab_ordrar_canonical_personnummer(array $row): string
{
    $id = (int) ($row['reminder_id'] ?? 0);
    $memoKey = '';
    if ($id > 0) {
        $ownerPnr = '';
        if (is_array($row['owners'] ?? null)) {
            foreach ($row['owners'] as $person) {
                if (is_array($person)) {
                    $ownerPnr .= (string) ($person['personnummer'] ?? $person['pnr'] ?? '') . ';';
                }
            }
        }
        $memoKey = $id
            . '|' . (string) ($row['updated_at'] ?? '')
            . '|' . (string) ($row['huvudsokande'] ?? '')
            . '|' . (string) ($row['contact_id'] ?? '')
            . '|' . (string) ($row['personnummer'] ?? '')
            . '|' . $ownerPnr;
        $hit = $GLOBALS['_JEAB_ORDRAR_PNR'][$memoKey] ?? null;
        if (is_string($hit)) {
            return $hit;
        }
    }
    $pnr = jeab_ordrar_canonical_personnummer_lookup($row);
    if ($memoKey !== '') {
        if (!isset($GLOBALS['_JEAB_ORDRAR_PNR']) || !is_array($GLOBALS['_JEAB_ORDRAR_PNR'])) {
            $GLOBALS['_JEAB_ORDRAR_PNR'] = [];
        }
        $GLOBALS['_JEAB_ORDRAR_PNR'][$memoKey] = $pnr;
    }

    return $pnr;
}

function jeab_ordrar_canonical_personnummer_lookup(array $row): string
{
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole()) {
        $fromContact = jeab_ordrar_contact_owner_personnummer($row);
        if ($fromContact !== '') {
            return $fromContact;
        }
        $linked = function_exists('jeab_contacts_sole_recipient_contact')
            && is_array(jeab_contacts_sole_recipient_contact($row));
        if ($linked) {
            return jeab_ordrar_order_owner_pnr($row);
        }
        // SQL-kontakter är källan. En olänkad order lämnar numret. JSON-labb utan det
        // registret läser ägarkortet på ordern (Bekräfta efter pnr-flytten).
        if (function_exists('jeab_kontakter_sql_is_sole_source') && jeab_kontakter_sql_is_sole_source()) {
            return '';
        }
    }
    $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];
    $idx = jeab_ordrar_huvud_owner_index($owners, (string) ($row['huvudsokande'] ?? ''));
    if ($idx !== null) {
        $fromOwner = jeab_ordrar_format_pnr((string) ($owners[$idx]['personnummer'] ?? ''));
        if ($fromOwner !== '') {
            return $fromOwner;
        }
    }

    return jeab_ordrar_format_pnr((string) ($row['personnummer'] ?? ''));
}

/**
 * Ägarens personnummer på kontakten. Tomt när sloten saknar nummer.
 * Orderns nummer används inte. Värdet skrivs inte i logg.
 *
 * @param array<string, mixed> $row
 */
function jeab_ordrar_contact_owner_personnummer(array $row): string
{
    if (!function_exists('jeab_contacts_sole_recipient_contact') || !function_exists('jeab_contacts_sole_contact_owner_pnr')) {
        return '';
    }
    $contact = jeab_contacts_sole_recipient_contact($row);
    if (!is_array($contact)) {
        return '';
    }
    $name = '';
    if (function_exists('jeab_contacts_sole_view_from_contact')) {
        $view = jeab_contacts_sole_view_from_contact($contact);
        $name = trim((string) ($view['name'] ?? ''));
    }
    if ($name === '') {
        foreach (['huvudsokande', 'customer_name', 'name'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $name = $value;
                break;
            }
        }
    }
    if ($name === '') {
        return '';
    }
    $pnr = jeab_contacts_sole_contact_owner_pnr($contact, $name);

    return $pnr === '' ? '' : jeab_ordrar_format_pnr($pnr);
}

/**
 * Huvudsökandens personnummer på ägarkortet är källan.
 * Tomt kort får det gamla övre värdet. Ett handskrivet övre värde skrivs inte över.
 */
function jeab_ordrar_mirror_huvud_pnr(array $row): array
{
    $owners = jeab_ordrar_people_list($row['owners'] ?? [], 8);
    $idx = jeab_ordrar_huvud_owner_index($owners, (string) ($row['huvudsokande'] ?? ''));
    if ($idx === null) {
        return $row;
    }
    $ownerPnr = jeab_ordrar_format_pnr((string) ($owners[$idx]['personnummer'] ?? ''));
    $legacy = jeab_ordrar_format_pnr((string) ($row['personnummer'] ?? ''));
    if ($ownerPnr !== '') {
        if ($legacy === '' || jeab_ordrar_identity_source($row, 'personnummer') !== 'manual') {
            $row['personnummer'] = $ownerPnr;
        }
        return $row;
    }
    if ($legacy === '') {
        return $row;
    }
    $owners[$idx]['personnummer'] = $legacy;
    $row['owners'] = $owners;
    return $row;
}

/** Tar bort personnummer ur text som kan hamna i svar, fel eller logg. */
function jeab_ordrar_scrub_pnr(mixed $value): mixed
{
    if (is_string($value)) {
        return preg_replace('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', '[pnr]', $value) ?? $value;
    }
    if (!is_array($value)) {
        return $value;
    }
    foreach ($value as $key => $item) {
        $value[$key] = jeab_ordrar_scrub_pnr($item);
    }
    return $value;
}

function jeab_ordrar_identity_missing(array $row): array
{
    $missing = [];
    if (jeab_ordrar_canonical_personnummer($row) === '') {
        $missing[] = 'personnummer';
    }
    if (trim((string) ($row['fastighetsbeteckning'] ?? '')) === '') {
        $missing[] = 'fastighetsbeteckning';
    }
    if (trim((string) ($row['huvudsokande'] ?? '')) === '') {
        $missing[] = 'huvudsökande';
    }
    return $missing;
}

function jeab_ordrar_reminder_scalar(array $source, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $source) || is_array($source[$key])) {
            continue;
        }
        $value = trim((string) $source[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/** En person, eller en lista. `pnr`/`namn` räknas som personnummer och namn. */
function jeab_ordrar_people_or_one(mixed $raw, int $max = 3): array
{
    if (!is_array($raw) || $raw === []) {
        return [];
    }
    $looksLikePerson = isset($raw['name']) || isset($raw['namn']) || isset($raw['personnummer']) || isset($raw['pnr']) || isset($raw['ssn']);
    if ($looksLikePerson && !array_is_list($raw)) {
        $person = jeab_ordrar_person($raw);
        if ($person['name'] === '' && $person['personnummer'] === '') {
            return [];
        }
        return [$person];
    }
    return jeab_ordrar_people_list($raw, $max);
}

function jeab_ordrar_note_pnr(string $text): string
{
    if (preg_match('/\b(\d{8}-\d{4}|\d{6}-\d{4}|\d{12})\b/u', $text, $m) === 1) {
        return jeab_ordrar_format_pnr($m[1]);
    }
    if (preg_match('/\b(\d{10})\b/u', $text, $m) === 1 && !str_starts_with($m[1], '07')) {
        return jeab_ordrar_format_pnr($m[1]);
    }
    return '';
}

function jeab_ordrar_person_from_note_tail(string $tail): array
{
    $pnr = jeab_ordrar_note_pnr($tail);
    if ($pnr !== '') {
        $tail = trim((string) preg_replace('/\b(?:\d{8}-\d{4}|\d{6}-\d{4}|\d{12}|\d{10})\b/u', ' ', $tail));
    }
    $tail = trim($tail, " \t,;");
    return jeab_ordrar_person([
        'name' => $tail,
        'personnummer' => $pnr,
    ]);
}

/**
 * Kund- och fastighetsrader som redan står på påminnelsen (fält eller anteckning).
 *
 * @return array{personnummer:string,fastighetsbeteckning:string,huvudsokande:string,huvudsokande_address:string,huvudsokande_andel:string,huvudsokande_lagfart:string,medsokande:array}
 */
function jeab_ordrar_reminder_identity(?array $reminder): array
{
    $empty = [
        'personnummer' => '',
        'fastighetsbeteckning' => '',
        'huvudsokande' => '',
        'huvudsokande_address' => '',
        'huvudsokande_andel' => '',
        'huvudsokande_lagfart' => '',
        'medsokande' => [],
    ];
    if (!is_array($reminder)) {
        return $empty;
    }
    $applicant = is_array($reminder['applicant'] ?? null) ? $reminder['applicant'] : [];
    $pnr = jeab_ordrar_format_pnr(jeab_ordrar_reminder_scalar($reminder, [
        'personnummer', 'pnr', 'ssn', 'personnr', 'huvudsokande_personnummer', 'huvudsokande_pnr', 'huvud_pnr',
    ]));
    if ($pnr === '') {
        $pnr = jeab_ordrar_format_pnr(jeab_ordrar_reminder_scalar($applicant, ['personnummer', 'pnr', 'ssn']));
    }
    $fast = jeab_ordrar_format_beteckning(jeab_ordrar_reminder_scalar($reminder, [
        'fastighetsbeteckning', 'beteckning', 'fastighet', 'propertyDesignation', 'PropertyDesignation',
    ]));
    $huvud = jeab_ordrar_reminder_scalar($reminder, ['huvudsokande', 'huvudsökande', 'huvud_sokande']);
    if ($huvud === '') {
        $huvud = trim((string) ($applicant['name'] ?? $applicant['namn'] ?? ''));
    }
    $andel = jeab_ordrar_reminder_scalar($reminder, ['huvudsokande_andel', 'andel']);
    $lagfart = jeab_ordrar_reminder_scalar($reminder, ['huvudsokande_lagfart', 'lagfart', 'inskrivningsdag']);
    $med = [];
    foreach (['medsokande', 'medsökande', 'medsokare', 'medsökare', 'coapplicant', 'co_applicant', 'coapplicants'] as $key) {
        if ($med !== [] || !array_key_exists($key, $reminder)) {
            continue;
        }
        $med = jeab_ordrar_people_or_one($reminder[$key]);
    }
    $note = function_exists('jeab_reminder_notes_text') ? jeab_reminder_notes_text($reminder) : trim((string) ($reminder['note'] ?? $reminder['notes'] ?? ''));
    $noteMed = $med === [];
    if ($note !== '') {
        $lines = preg_split('/\R/u', $note) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if ($pnr === '' && preg_match('/^(?:personnummer|personnr|pnr|ssn)\s*[:：]?\s*(.+)$/iu', $line, $m) === 1) {
                $parsedPnr = jeab_ordrar_note_pnr($m[1]);
                if ($parsedPnr !== '') {
                    $pnr = $parsedPnr;
                }
                continue;
            }
            if ($fast === '' && preg_match('/^(?:fastighetsbeteckning|fastighet|beteckning)\s*[:：]?\s*(.+)$/iu', $line, $m) === 1) {
                $fast = jeab_ordrar_format_beteckning(trim($m[1]));
                continue;
            }
            if ($huvud === '' && preg_match('/^(?:huvudsökande|huvudsokande)\s*[:：]?\s*(.+)$/iu', $line, $m) === 1) {
                $huvud = trim($m[1]);
                continue;
            }
            if ($noteMed && preg_match('/^(?:medsökande|medsokande|medsökare|medsokare)\s*[:：]?\s*(.+)$/iu', $line, $m) === 1) {
                $person = jeab_ordrar_person_from_note_tail(trim($m[1]));
                if ($person['name'] !== '' || $person['personnummer'] !== '') {
                    $med[] = $person;
                }
            }
        }
        if ($fast === '' && preg_match('/\b(\p{L}[\p{L}\-]*\s+\d+\s*:\s*\d+)\b/u', $note, $m) === 1) {
            $fast = jeab_ordrar_format_beteckning($m[1]);
        }
    }
    $owners = jeab_ordrar_people_or_one($reminder['owners'] ?? $reminder['agare'] ?? $reminder['ägare'] ?? [], 4);
    if ($owners !== []) {
        $picked = jeab_ordrar_find_owner($owners, $huvud, $pnr);
        if ($pnr === '' && trim((string) ($picked['personnummer'] ?? '')) !== '') {
            $pnr = (string) $picked['personnummer'];
        }
        if ($huvud === '' && trim((string) ($picked['name'] ?? '')) !== '') {
            $huvud = (string) $picked['name'];
        }
        if ($med === []) {
            $med = jeab_ordrar_other_owners($owners, $huvud);
        }
    }
    if ($huvud === '') {
        $huvud = jeab_ordrar_customer_name($reminder);
    }
    $kept = [];
    foreach ($med as $person) {
        if (!is_array($person)) {
            continue;
        }
        if ($huvud !== '' && jeab_ordrar_same_person($huvud, (string) ($person['name'] ?? ''))) {
            continue;
        }
        if (trim((string) ($person['name'] ?? '')) === '' && trim((string) ($person['personnummer'] ?? '')) === '') {
            continue;
        }
        $kept[] = $person;
        if (count($kept) >= 8) {
            break;
        }
    }
    $addr = trim((string) ($reminder['address'] ?? ''));
    if ($addr === '') {
        $addr = trim((string) ($reminder['huvudsokande_address'] ?? ''));
    }
    return [
        'personnummer' => $pnr,
        'fastighetsbeteckning' => $fast,
        'huvudsokande' => $huvud,
        'huvudsokande_address' => $addr,
        'huvudsokande_andel' => $andel,
        'huvudsokande_lagfart' => $lagfart,
        'medsokande' => $kept,
    ];
}

/** Adress till ordern utan postnummer. Postnumret fylls av adressuppslaget, inte av leadraden. */
function jeab_ordrar_identity_address(string $address): string
{
    $address = trim($address);
    if ($address === '' || !function_exists('jeab_reminder_strip_postnummer')) {
        return $address;
    }
    $stripped = jeab_reminder_strip_postnummer($address);
    return $stripped !== '' ? $stripped : $address;
}

/** Fyll tomma orderfält från påminnelsen. Redan sparade värden lämnas orörda. */
function jeab_ordrar_apply_reminder_identity(array $row, ?array $reminder): array
{
    $id = jeab_ordrar_reminder_identity($reminder);
    if (trim((string) ($row['huvudsokande'] ?? '')) === '' && $id['huvudsokande'] !== '') {
        $row = jeab_ordrar_apply_primary_name($row, $id['huvudsokande']);
    }
    if (trim((string) ($row['personnummer'] ?? '')) === '' && $id['personnummer'] !== '') {
        $row['personnummer'] = $id['personnummer'];
    }
    if (trim((string) ($row['fastighetsbeteckning'] ?? '')) === '' && $id['fastighetsbeteckning'] !== '') {
        $row['fastighetsbeteckning'] = $id['fastighetsbeteckning'];
    }
    $copiedAddress = jeab_ordrar_identity_address($id['huvudsokande_address']);
    if (trim((string) ($row['huvudsokande_address'] ?? '')) === '' && $copiedAddress !== '') {
        $row['huvudsokande_address'] = $copiedAddress;
    }
    if (trim((string) ($row['address'] ?? '')) === '' && $copiedAddress !== '') {
        $row['address'] = $copiedAddress;
    }
    if (trim((string) ($row['huvudsokande_andel'] ?? '')) === '' && $id['huvudsokande_andel'] !== '') {
        $row['huvudsokande_andel'] = $id['huvudsokande_andel'];
    }
    if (trim((string) ($row['huvudsokande_lagfart'] ?? '')) === '' && $id['huvudsokande_lagfart'] !== '') {
        $row['huvudsokande_lagfart'] = $id['huvudsokande_lagfart'];
    }
    if (jeab_ordrar_people_list($row['medsokande'] ?? []) === [] && $id['medsokande'] !== []) {
        $row['medsokande'] = $id['medsokande'];
    }
    return jeab_ordrar_mirror_huvud_pnr($row);
}

/**
 * Kopiera påminnelsens kund/fastighet till ordern. Adressuppslag fyller bara det som fortfarande saknas.
 */
function jeab_ordrar_prepare_open_identity(array $row, ?array $reminder, bool $allowLookup): array
{
    $before = $row;
    $row = jeab_ordrar_apply_reminder_identity($row, $reminder);
    $reminderRow = $row;
    if (jeab_ordrar_identity_missing($row) !== [] && $allowLookup && jeab_ordrar_external_live()) {
        $row = jeab_ordrar_backfill_lookup($row, $reminder, false);
        if (jeab_ordrar_identity_missing($row) !== []) {
            $row['identity_filled_at'] = jeab_now();
        } else {
            $row['identity_filled_at'] = null;
        }
    }
    $persist = $reminderRow;
    if (array_key_exists('identity_filled_at', $row)) {
        $persist['identity_filled_at'] = $row['identity_filled_at'];
    }
    $zipBefore = $persist;
    [$persist] = jeab_ordrar_apply_lookup_postnummer($persist, $row);
    if (jeab_ordrar_identity_value($zipBefore, 'postnummer') === '' && jeab_ordrar_identity_value($persist, 'postnummer') !== '') {
        $persist = jeab_ordrar_mark_identity_source($persist, 'postnummer', 'lookup', jeab_ordrar_lookup_query($reminder, $persist));
    }
    $persist = jeab_ordrar_keep_stored_fields($before, $persist);
    $beforeJson = json_encode($before);
    $persistJson = json_encode($persist);
    if ($beforeJson === $persistJson) {
        return $row;
    }
    $id = (int) ($persist['reminder_id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_purchase_get') || !is_array(jeab_purchase_get($id)) || !function_exists('jeab_purchase_put')) {
        return $row;
    }
    $persist['updated_at'] = jeab_now();
    $saved = jeab_ordrar_put_leaving_note($persist);
    foreach (['updated_at', 'identity_filled_at', 'postnummer', 'postnr', 'ort', 'identity_source'] as $key) {
        if (array_key_exists($key, $saved)) {
            $row[$key] = $saved[$key];
        }
    }
    return $row;
}

/**
 * En sparning får inte tömma produkt, nätägare eller kundfält som redan finns.
 * Tomma nycklar i $persist återställs från $before. Saknade nycklar rörs inte.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $persist
 * @return array<string, mixed>
 */
function jeab_ordrar_keep_stored_fields(array $before, array $persist): array
{
    foreach ([
        'kind', 'natbolag', 'address', 'huvudsokande_address', 'gata', 'ort',
        'postnummer', 'postnr', 'huvudsokande', 'name', 'customer_name', 'email', 'phone',
    ] as $key) {
        if (!array_key_exists($key, $persist)) {
            continue;
        }
        $prev = trim((string) ($before[$key] ?? ''));
        $next = trim((string) ($persist[$key] ?? ''));
        if ($prev !== '' && $next === '') {
            $persist[$key] = $before[$key];
        }
    }

    return $persist;
}

function jeab_ordrar_reminder_for_row(array $row, int $openId = 0): ?array
{
    $ids = [];
    foreach ([(int) ($row['reminder_id'] ?? 0), $openId, (int) ($row['id'] ?? 0)] as $id) {
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    if ($ids === []) {
        return null;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return null;
    }
    $found = [];
    foreach ($ids as $id) {
        try {
            $reminder = jeab_get_reminder($store, $id);
        } catch (Throwable) {
            continue;
        }
        if (is_array($reminder)) {
            $found[] = $reminder;
        }
    }
    foreach ($found as $reminder) {
        if (jeab_ordrar_customer_name($reminder) !== '') {
            return $reminder;
        }
    }
    return $found[0] ?? null;
}

function jeab_ordrar_apply_suggest(array $row, array $suggest): array
{
    $huvud = trim((string) ($suggest['huvudsokande'] ?? ''));
    $pnr = trim((string) ($suggest['personnummer'] ?? ''));
    $fast = trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
    $addr = trim((string) ($suggest['huvudsokande_address'] ?? $suggest['address'] ?? ''));
    $keepSaved = trim((string) ($row['personnummer'] ?? '')) !== ''
        || jeab_ordrar_people_list($row['medsokande'] ?? []) !== [];
    $overwrite = !empty($suggest['name_matched'])
        && !$keepSaved
        && !jeab_ordrar_honor_huvudsokande_pick($row, (string) ($suggest['match_first'] ?? ''));
    if ($huvud !== '' && ($overwrite || trim((string) ($row['huvudsokande'] ?? '')) === '')) {
        $row['huvudsokande'] = $huvud;
    }
    if ($pnr !== '' && ($overwrite || trim((string) ($row['personnummer'] ?? '')) === '')) {
        $row['personnummer'] = $pnr;
    }
    if ($fast !== '' && trim((string) ($row['fastighetsbeteckning'] ?? '')) === '') {
        $row['fastighetsbeteckning'] = $fast;
    }
    if ($addr !== '' && ($overwrite || trim((string) ($row['huvudsokande_address'] ?? '')) === '')) {
        $row['huvudsokande_address'] = $addr;
    }
    if ($addr !== '' && trim((string) ($row['address'] ?? '')) === '') {
        $row['address'] = $addr;
    }
    $med = is_array($suggest['medsokande'] ?? null) ? $suggest['medsokande'] : [];
    if ($med !== [] && ($overwrite || jeab_ordrar_people_list($row['medsokande'] ?? []) === [])) {
        $row['medsokande'] = $med;
    }
    [$row] = jeab_ordrar_apply_lookup_postnummer($row, $suggest);
    return $row;
}

function jeab_ordrar_apply_matched_owner(array $row, array $suggest): array
{
    $huvud = trim((string) ($suggest['huvudsokande'] ?? ''));
    if ($huvud === '') {
        return $row;
    }
    $row['huvudsokande'] = $huvud;
    $pnr = trim((string) ($suggest['personnummer'] ?? ''));
    if ($pnr !== '') {
        $row['personnummer'] = $pnr;
    }
    $addr = trim((string) ($suggest['huvudsokande_address'] ?? $suggest['address'] ?? ''));
    if ($addr !== '') {
        $row['huvudsokande_address'] = $addr;
        if (trim((string) ($row['address'] ?? '')) === '') {
            $row['address'] = $addr;
        }
    }
    $fast = trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
    if ($fast !== '') {
        $row['fastighetsbeteckning'] = $fast;
    }
    $med = is_array($suggest['medsokande'] ?? null) ? $suggest['medsokande'] : [];
    if ($med !== []) {
        $row['medsokande'] = $med;
    }
    [$row] = jeab_ordrar_apply_lookup_postnummer($row, $suggest);
    return $row;
}

function jeab_ordrar_sync_matched_owner(array $row, ?array $reminder = null, bool $save = true): array
{
    if (!is_array($reminder)) {
        $reminder = jeab_ordrar_reminder_for_row($row);
    }
    $first = jeab_ordrar_first_name(jeab_ordrar_person_name($reminder, $row));
    if (jeab_ordrar_honor_huvudsokande_pick($row, $first)) {
        return $row;
    }
    $suggest = jeab_ordrar_confirm_suggest($row, $reminder);
    if (empty($suggest['name_matched'])) {
        return $row;
    }
    $matched = trim((string) ($suggest['huvudsokande'] ?? ''));
    if ($matched === '') {
        return $row;
    }
    $saved = trim((string) ($row['huvudsokande'] ?? ''));
    $savedPnr = jeab_ordrar_format_pnr((string) ($row['personnummer'] ?? ''));
    $matchedPnr = jeab_ordrar_format_pnr((string) ($suggest['personnummer'] ?? ''));
    $savedMed = jeab_ordrar_people_list($row['medsokande'] ?? []);
    $matchedMed = jeab_ordrar_people_list($suggest['medsokande'] ?? []);
    $sameMed = count($savedMed) === count($matchedMed);
    if ($sameMed) {
        foreach ($matchedMed as $i => $person) {
            if (!jeab_ordrar_same_person((string) ($savedMed[$i]['name'] ?? ''), (string) ($person['name'] ?? ''))) {
                $sameMed = false;
                break;
            }
        }
    }
    if ($saved !== '' && jeab_ordrar_same_person($saved, $matched) && ($savedPnr === '' || $matchedPnr === '' || $savedPnr === $matchedPnr) && $sameMed) {
        return $row;
    }
    $filled = jeab_ordrar_apply_matched_owner($row, $suggest);
    foreach (['personnummer', 'fastighetsbeteckning'] as $field) {
        $mark = is_array($row['identity_source'][$field] ?? null) ? $row['identity_source'][$field] : [];
        if ((string) ($mark['src'] ?? '') === 'manual' && jeab_ordrar_identity_source($row, $field) === 'manual') {
            $filled[$field] = $row[$field];
        }
    }
    $filled = jeab_ordrar_mark_lookup_fills($row, $filled, jeab_ordrar_lookup_query($reminder, $row));
    $filled['huvudsokande_manual'] = false;
    if (!$save) {
        return $filled;
    }
    $filled['updated_at'] = jeab_now();
    return jeab_purchase_put($filled);
}

function jeab_ordrar_backfill_lookup(array $row, ?array $reminder = null, bool $save = false): array
{
    $beforeZip = jeab_ordrar_postnummer_digits((string) ($row['postnummer'] ?? $row['postnr'] ?? ''));
    $beforeMissing = jeab_ordrar_identity_missing($row);
    if ($beforeMissing === [] && $beforeZip !== '') {
        return $row;
    }
    if (!is_array($reminder)) {
        $reminder = jeab_ordrar_reminder_for_row($row);
    }
    $suggest = jeab_ordrar_confirm_suggest($row, $reminder);
    $filled = $beforeMissing === [] ? $row : jeab_ordrar_apply_suggest($row, $suggest);
    if ($beforeMissing === []) {
        [$filled] = jeab_ordrar_apply_lookup_postnummer($filled, $suggest);
    }
    if (jeab_ordrar_postnummer_digits((string) ($filled['postnummer'] ?? $filled['postnr'] ?? '')) === '') {
        [$filled] = jeab_ordrar_fill_postnummer($filled, $reminder);
    }
    $filled = jeab_ordrar_mark_lookup_fills($row, $filled, jeab_ordrar_lookup_query($reminder, $row));
    $zipChanged = jeab_ordrar_postnummer_digits((string) ($filled['postnummer'] ?? '')) !== $beforeZip;
    $identityChanged = jeab_ordrar_identity_missing($filled) !== $beforeMissing;
    if (!$save || (!$zipChanged && !$identityChanged)) {
        return $filled;
    }
    $filled['updated_at'] = jeab_now();
    return jeab_purchase_put($filled);
}

function jeab_ordrar_save_confirm(array $input): array
{
    return jeab_ordrar_save_questions($input);
}

function jeab_ordrar_save_questions(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $beforeQuestions = $row;
    $kind = jeab_purchase_normalize_kind($input['kind'] ?? '');
    if ($kind === null) {
        throw new InvalidArgumentException('Välj Elbilsladdare, Batteri eller Solpaneler.');
    }
    $row['kind'] = $kind;
    $row = jeab_ordrar_apply_confirm_fields($row, $input);
    $row['install_what'] = trim((string) ($input['install_what'] ?? $row['install_what'] ?? ''));
    $prevPris = (int) ($row['pris'] ?? 0);
    $row['pris'] = (int) round(jeab_purchase_amount($input['pris'] ?? $row['pris'] ?? 0));
    $row['install_note'] = trim((string) ($input['install_note'] ?? $row['install_note'] ?? ''));
    if ($kind === 'batteri') {
        // Bekräfta-fältet Att betala är kundpris. Det är det Fortnox-raderna räknas på.
        if ($row['pris'] > 0) {
            $row['kundpris'] = $row['pris'];
        } elseif ((int) ($row['kundpris'] ?? 0) > 0) {
            $row['pris'] = (int) $row['kundpris'];
        }
        // Stängda ordrar och ordrar utan Sigen-val räknas inte om.
        if (function_exists('jeab_sigen_frozen') && !jeab_sigen_frozen($row)) {
            $typ = strtolower(trim((string) ($input['sigen_typ'] ?? '')));
            if ($typ !== '') {
                $kalkyl = jeab_ordrar_kalkyl_from_input($input);
                if (is_array($kalkyl)) {
                    $row['kalkyl'] = $kalkyl;
                    $row = jeab_sigen_apply_inkop($row);
                }
            }
        }
    } elseif ($kind === 'sol') {
        // Samma regel som tidigare: kundpris följer Bekräfta-priset när det ändras.
        // Oförändrat pris lämnar ett avvikande kundpris. Kalkylen sparas separat.
        $existingKundpris = (int) ($row['kundpris'] ?? 0);
        $prisEdited = $row['pris'] > 0 && $row['pris'] !== $prevPris;
        if ($existingKundpris <= 0 || $prisEdited) {
            $row['kundpris'] = jeab_ordrar_resolve_kundpris($row['pris'], $existingKundpris);
        }
        $kalkyl = jeab_ordrar_kalkyl_from_input($input);
        if (is_array($kalkyl)) {
            $row['kalkyl'] = $kalkyl;
        }
    } elseif ($kind !== 'laddbox') {
        $existingKundpris = (int) ($row['kundpris'] ?? 0);
        $prisEdited = $row['pris'] > 0 && $row['pris'] !== $prevPris;
        if ($existingKundpris <= 0 || $prisEdited) {
            $row['kundpris'] = jeab_ordrar_resolve_kundpris($row['pris'], $existingKundpris);
        }
    }
    if (!is_array($row['steps'] ?? null)) {
        $row['steps'] = [];
    }
    if (trim((string) ($row['steps']['fatt_alla_uppgifter'] ?? '')) === '') {
        $row['steps']['fatt_alla_uppgifter'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
    }
    $row = jeab_ordrar_stamp_today($row, 'bekraftad');
    if ($kind === 'laddbox') {
        $row['stop3_saved'] = true;
    }
    if (function_exists('jeab_ordrar_payouts_posted') && jeab_ordrar_payouts_posted($input)) {
        $row = jeab_ordrar_payouts_merge($row, $input);
    }
    $row['updated_at'] = jeab_now();
    if (function_exists('jeab_contacts_sole_divert_order')) {
        $row = jeab_contacts_sole_divert_order($beforeQuestions, $row);
    }
    $row = jeab_purchase_put($row);
    jeab_ordrar_sync_lm_contact($row);
    jeab_ordrar_refresh_linked_booking_notes($row);
    return $row;
}

/**
 * Ett lås per påminnelse för «Skapa order och boka» och faktura.
 * MySQL väntar på GET_LOCK. Övriga drivare (och samma maskin) väntar på fil-låset.
 * Samma anrop får gå in igen.
 */
function jeab_ordrar_reminder_effect_lock(int $id, callable $fn): mixed
{
    if ($id <= 0) {
        return $fn();
    }
    if (!empty($GLOBALS['_JEAB_ORDRAR_EFFECT'][$id])) {
        return $fn();
    }
    $GLOBALS['_JEAB_ORDRAR_EFFECT'][$id] = true;
    $pdo = null;
    $lockName = '';
    $held = false;
    try {
        $lockName = function_exists('jeab_db_lock_name')
            ? jeab_db_lock_name('jeab_ordrar_eff_' . $id)
            : 'jeab_ordrar_eff_' . $id;
        if (function_exists('jeab_store')) {
            try {
                $store = jeab_store();
                if (class_exists('JeabPdoStore') && $store instanceof JeabPdoStore) {
                    $pdo = $store->pdo();
                }
            } catch (Throwable) {
                $pdo = null;
            }
        }
        if ($pdo instanceof PDO && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            try {
                $stmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 20)');
                $held = $stmt && (int) $stmt->fetchColumn() === 1;
            } catch (Throwable) {
                $held = false;
            }
            if (!$held) {
                if (function_exists('jeab_purchase_cache_forget')) {
                    jeab_purchase_cache_forget();
                }
                $fresh = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
                $orderNo = is_array($fresh) ? trim((string) ($fresh['fortnox_order'] ?? '')) : '';
                $booked = is_array($fresh) && function_exists('jeab_ordrar_is_booked') && jeab_ordrar_is_booked($fresh);
                if ($orderNo !== '' && $booked) {
                    return $fresh;
                }
                throw new InvalidArgumentException('Ordern skapas redan. Vänta en stund och ladda om.');
            }
        }
        $call = static function () use ($fn) {
            // Anropet kan ha läst ordern innan det fick låset. Den cachen
            // ser inte den andra processens faktura eller Fortnox-order.
            if (function_exists('jeab_purchase_cache_forget')) {
                jeab_purchase_cache_forget();
            }

            return $fn();
        };
        if (function_exists('jeab_reminder_lock')) {
            return jeab_reminder_lock($id, $call);
        }

        return $call();
    } finally {
        if ($held && $pdo instanceof PDO && $lockName !== '') {
            try {
                $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
            } catch (Throwable) {
            }
        }
        unset($GLOBALS['_JEAB_ORDRAR_EFFECT'][$id]);
    }
}

function jeab_ordrar_slutfor(JeabStore $store, array $input): array
{
    $id = (int) ($input['id'] ?? 0);
    $run = static function () use ($store, $input): array {
        $row = jeab_ordrar_save_questions($input);
        $kind = (string) ($row['kind'] ?? '');
        // Laddbox, batteri och sol bokar första lediga tid och skriver Fortnox.
        if ($kind !== 'laddbox' && $kind !== 'batteri' && $kind !== 'sol') {
            return $row;
        }
        $reminderId = (int) ($row['reminder_id'] ?? $input['id'] ?? 0);
        if ($reminderId > 0 && function_exists('jeab_purchase_get')) {
            $fresh = jeab_purchase_get($reminderId);
            if (is_array($fresh)) {
                $row = $fresh;
            }
        }
        // Batteri och sol bokas i Beställ, när säljaren sett förslaget.
        // Laddbox har inget Beställ-steg och bokas här, som tidigare.
        $booked = function_exists('jeab_ordrar_is_booked') && jeab_ordrar_is_booked($row);
        if (!$booked && !jeab_ordrar_books_at_bestall($row)) {
            $row = jeab_ordrar_create_booking($store, [
                'id' => $reminderId,
                'install_date' => (string) ($input['install_date'] ?? ''),
                'install_time' => (string) ($input['install_time'] ?? ''),
            ]);
        }
        if (trim((string) ($row['fortnox_order'] ?? '')) === '' && !jeab_ordrar_fortnox_attach_locked($row)) {
            $row = jeab_ordrar_fortnox_on_next($row);
        }

        return $row;
    };
    if ($id > 0 && function_exists('jeab_ordrar_reminder_effect_lock')) {
        $done = jeab_ordrar_reminder_effect_lock($id, $run);
        if (!is_array($done)) {
            throw new InvalidArgumentException('Ordern skapades inte.');
        }

        return $done;
    }

    return $run();
}

/**
 * Skriver Fortnox-ordern från Bekräfta utan kalenderbokning och utan kundutskick.
 * Finns ett ordernummer redan lämnas Fortnox orörd.
 */
function jeab_ordrar_fortnox_utan_bokning(array $input): array
{
    $id = (int) ($input['id'] ?? 0);
    $run = static function () use ($input): array {
        $row = jeab_ordrar_save_questions($input);
        $reminderId = (int) ($row['reminder_id'] ?? $input['id'] ?? 0);
        if ($reminderId > 0 && function_exists('jeab_purchase_get')) {
            $fresh = jeab_purchase_get($reminderId);
            if (is_array($fresh)) {
                $row = $fresh;
            }
        }
        if (trim((string) ($row['fortnox_order'] ?? '')) !== '' || jeab_ordrar_fortnox_attach_locked($row)) {
            return $row;
        }

        return jeab_ordrar_create_fortnox(['id' => $reminderId]);
    };
    if ($id > 0 && function_exists('jeab_ordrar_reminder_effect_lock')) {
        $done = jeab_ordrar_reminder_effect_lock($id, $run);
        if (!is_array($done)) {
            throw new InvalidArgumentException('Ordern skapades inte.');
        }

        return $done;
    }

    return $run();
}

function jeab_ordrar_resolve_store(?JeabStore $store = null): ?JeabStore
{
    if ($store instanceof JeabStore) {
        return $store;
    }
    if (!function_exists('jeab_store')) {
        return null;
    }
    try {
        $resolved = jeab_store();
    } catch (Throwable) {
        return null;
    }
    return $resolved instanceof JeabStore ? $resolved : null;
}

function jeab_ordrar_linked_booking(array $row, ?JeabStore $store = null): ?array
{
    $id = (int) ($row['booking_id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_get_booking')) {
        return null;
    }
    $bookMemo = $GLOBALS['_JEAB_ORDRAR_BOOKING'] ?? null;
    if (is_array($bookMemo) && array_key_exists($id, $bookMemo)) {
        $hit = $bookMemo[$id];

        return is_array($hit) ? $hit : null;
    }
    $store = jeab_ordrar_resolve_store($store);
    if (!$store instanceof JeabStore) {
        return null;
    }
    try {
        $booking = jeab_get_booking($store, $id);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($booking) || ($booking['status'] ?? '') === 'cancelled') {
        $booking = null;
    }
    if (!isset($GLOBALS['_JEAB_ORDRAR_BOOKING']) || !is_array($GLOBALS['_JEAB_ORDRAR_BOOKING'])) {
        $GLOBALS['_JEAB_ORDRAR_BOOKING'] = [];
    }
    $GLOBALS['_JEAB_ORDRAR_BOOKING'][$id] = $booking ?? false;

    return $booking;
}

function jeab_ordrar_booking_times(array $row, ?JeabStore $store = null): array
{
    $booking = jeab_ordrar_linked_booking($row, $store);
    $start = is_array($booking) ? trim((string) ($booking['starts_at'] ?? '')) : '';
    $end = is_array($booking) ? trim((string) ($booking['ends_at'] ?? '')) : '';
    // Om booking_id pekar på cancelled/saknad: visa inte gammal steps.bokad_tid-stämpel.
    if ($start === '' && !is_array($booking) && (int) ($row['booking_id'] ?? 0) <= 0) {
        $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
        $start = trim((string) ($steps['bokad_tid'] ?? $row['bokad_tid'] ?? $row['starts_at'] ?? ''));
    }
    return ['starts_at' => $start, 'ends_at' => $end];
}

function jeab_ordrar_sync_bokad_tid_from_booking(array $booking): void
{
    if (($booking['status'] ?? '') === 'cancelled') {
        jeab_ordrar_clear_bokad_tid_from_booking($booking);
        return;
    }
    $bookingId = (int) ($booking['id'] ?? 0);
    $starts = trim((string) ($booking['starts_at'] ?? ''));
    if ($bookingId <= 0 || $starts === '' || !function_exists('jeab_purchase_by_booking') || !function_exists('jeab_purchase_put')) {
        return;
    }
    $row = jeab_purchase_by_booking($bookingId);
    if (!is_array($row)) {
        return;
    }
    if (!is_array($row['steps'] ?? null)) {
        $row['steps'] = [];
    }
    if (trim((string) ($row['steps']['bokad_tid'] ?? '')) === $starts) {
        return;
    }
    $row['steps']['bokad_tid'] = $starts;
    $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    try {
        jeab_purchase_put($row);
    } catch (Throwable) {
        // Ordersynk får inte stoppa kalendersparandet.
    }
    $deliveryFile = __DIR__ . '/fortnox_leveransdatum.php';
    if (is_file($deliveryFile)) {
        require_once $deliveryFile;
    }
    if (function_exists('jeab_fortnox_leveransdatum_on_booking')) {
        jeab_fortnox_leveransdatum_on_booking($booking);
    }
}

function jeab_ordrar_clear_bokad_tid_from_booking(array $booking): void
{
    // Laddbox-orörd / Fortnox-skrivet får inte blockera — put i try/catch.
    $bookingId = (int) ($booking['id'] ?? $booking['booking_id'] ?? 0);
    if ($bookingId <= 0 || !function_exists('jeab_purchase_by_booking') || !function_exists('jeab_purchase_put')) {
        return;
    }
    $row = jeab_purchase_by_booking($bookingId);
    if (!is_array($row)) {
        return;
    }
    if (!is_array($row['steps'] ?? null)) {
        $row['steps'] = [];
    }
    $had = trim((string) ($row['steps']['bokad_tid'] ?? '')) !== '';
    $row['steps']['bokad_tid'] = '';
    // Behåll booking_id för historik; display måste ignorera cancelled.
    if (!$had) {
        return;
    }
    $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    try {
        jeab_purchase_put($row);
    } catch (Throwable $e) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('ordrar', 'clear_bokad_tid failed booking=' . $bookingId . ': ' . $e->getMessage());
        }
    }
}

function jeab_ordrar_booking_when_label(array $row, ?JeabStore $store = null): string
{
    $times = jeab_ordrar_booking_times($row, $store);
    $raw = $times['starts_at'];
    if ($raw === '') {
        return '';
    }
    try {
        $start = new DateTimeImmutable($raw, new DateTimeZone('Europe/Stockholm'));
    } catch (Exception) {
        return jeab_ordrar_mmdd($raw);
    }
    $end = null;
    if ($times['ends_at'] !== '') {
        try {
            $end = new DateTimeImmutable($times['ends_at'], new DateTimeZone('Europe/Stockholm'));
        } catch (Exception) {
            $end = null;
        }
    }
    if ($end === null) {
        $hour = (int) $start->format('G');
        $end = $start->setTime($hour < 12 ? 11 : 16, 0);
    }
    $day = function_exists('jeab_sv_weekday') ? jeab_sv_weekday($start, false) : $start->format('l');
    $mon = function_exists('jeab_sv_month') ? jeab_sv_month($start) : $start->format('F');
    if ($end instanceof DateTimeImmutable && function_exists('jeab_booking_is_all_day') && jeab_booking_is_all_day($raw, $times['ends_at'])) {
        $last = $end->modify('-1 day');
        $lastDay = function_exists('jeab_sv_weekday') ? jeab_sv_weekday($last, false) : $last->format('l');
        $lastMon = function_exists('jeab_sv_month') ? jeab_sv_month($last) : $last->format('F');
        $n = function_exists('jeab_booking_weekday_count') ? jeab_booking_weekday_count($raw, $times['ends_at']) : 5;
        $span = $day . ' ' . (int) $start->format('j') . ' ' . $mon;
        if ($last->format('Y-m-d') !== $start->format('Y-m-d')) {
            $span .= '–' . $lastDay . ' ' . (int) $last->format('j') . ' ' . $lastMon;
        }
        return $span . ' (' . $n . ' dagar)';
    }
    return $day . ' ' . (int) $start->format('j') . ' ' . $mon . ' klockan ' . (int) $start->format('G') . ' till ' . (int) $end->format('G');
}

function jeab_ordrar_booking_cal_url(array $row, ?JeabStore $store = null): string
{
    $bookingId = (int) ($row['booking_id'] ?? 0);
    $raw = jeab_ordrar_booking_times($row, $store)['starts_at'];
    $w = 0;
    if ($raw !== '' && function_exists('jeab_monday_of') && function_exists('jeab_week_offset_for_monday')) {
        try {
            $start = new DateTimeImmutable($raw, new DateTimeZone('Europe/Stockholm'));
            $w = jeab_week_offset_for_monday(jeab_monday_of($start));
        } catch (Exception) {
            $w = 0;
        }
    }
    $kind = jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '';
    $who = jeab_ordrar_book_who($kind, isset($row['company']) ? (string) $row['company'] : null);
    return jeab_bookings_url([
        'co' => $row['company'] ?? null,
        'w' => $w,
        'id' => $bookingId > 0 ? $bookingId : null,
        'who' => $who !== '' ? $who : null,
    ]);
}

function jeab_ordrar_result_body(array $row, string $status = ''): string
{
    $orderNo = trim((string) ($row['fortnox_order'] ?? ''));
    $when = jeab_ordrar_booking_when_label($row);
    $html = '';
    if ($orderNo !== '') {
        $fxUrl = jeab_ordrar_fortnox_order_url($row);
        $value = $fxUrl !== ''
            ? '<a class="ordrar-result-link" href="' . e($fxUrl) . '" target="_blank" rel="noopener">' . e($orderNo) . '</a>'
            : '<span class="ordrar-result-link">' . e($orderNo) . '</span>';
        $html .= '<div class="ordrar-result-row"><span class="ordrar-result-lbl">Ordernummer Fortnox</span>' . $value . '</div>';
    }
    if ($when !== '') {
        $calUrl = jeab_ordrar_booking_cal_url($row);
        $value = $calUrl !== ''
            ? '<a class="ordrar-result-link" href="' . e($calUrl) . '">' . e($when) . '</a>'
            : '<span class="ordrar-result-link">' . e($when) . '</span>';
        $html .= '<div class="ordrar-result-row"><span class="ordrar-result-lbl">Bokad tid i kalendern</span>' . $value . '</div>';
        if (jeab_ordrar_ui_kind($row['kind'] ?? '') === 'sol') {
            $html .= '<p class="ordrar-field-help ordrar-leverans-note">Tiden kan flyttas senare om leveransen inte hinner fram (högst 7 dagar före installation).</p>';
        }
    }
    // «Sparad.» hör hemma vid knappen, inte som ensam rad till vänster.
    return $html;
}

function jeab_ordrar_result_html(array $row): string
{
    $body = jeab_ordrar_result_body($row);
    return $body === '' ? '' : '<div class="ordrar-result">' . $body . '</div>';
}

function jeab_ordrar_foranmalan_choices(): array
{
    return [
        'A' => 'Automatiskt',
        'B' => 'Spara endast som utkast',
        'C' => 'Manuellt/ingen',
    ];
}

function jeab_ordrar_foranmalan_case_statuses(): array
{
    return [
        'skickad' => 'Skickad',
        'nekad' => 'Nekad',
        'godkand' => 'Godkänd',
        'fardiganmald' => 'Färdiganmäld',
    ];
}

function jeab_ordrar_normalize_foranmalan_case_status(string $raw): string
{
    $raw = strtolower(trim($raw));
    $raw = str_replace(['ä', 'å', 'ö'], ['a', 'a', 'o'], $raw);
    $map = [
        'skickad' => 'skickad',
        'sent' => 'skickad',
        'nekad' => 'nekad',
        'denied' => 'nekad',
        'rejected' => 'nekad',
        'godkand' => 'godkand',
        'godkänd' => 'godkand',
        'approved' => 'godkand',
        'fardiganmald' => 'fardiganmald',
        'färdiganmäld' => 'fardiganmald',
        'complete' => 'fardiganmald',
    ];
    return $map[$raw] ?? (array_key_exists($raw, jeab_ordrar_foranmalan_case_statuses()) ? $raw : '');
}

function jeab_ordrar_foranmalan_case_status(array $row): string
{
    foreach (['foranmalan_case_status', 'foranmalan_lage', 'foranmalan_arende_status'] as $key) {
        $v = jeab_ordrar_normalize_foranmalan_case_status((string) ($row[$key] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    foreach (['foranmalan_case_status', 'foranmalan_lage'] as $key) {
        $v = jeab_ordrar_normalize_foranmalan_case_status((string) ($steps[$key] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

function jeab_ordrar_foranmalan_case_label(array $row): string
{
    $status = jeab_ordrar_foranmalan_case_status($row);
    $labels = jeab_ordrar_foranmalan_case_statuses();
    return $labels[$status] ?? '';
}

function jeab_ordrar_foranmalan_case_tone(array $row): string
{
    return match (jeab_ordrar_foranmalan_case_status($row)) {
        'nekad' => 'danger',
        'godkand' => 'warn',
        'fardiganmald' => 'ok',
        default => 'muted',
    };
}

function jeab_ordrar_fakturera_status(array $row): string
{
    if (jeab_ordrar_has_invoice($row)) {
        return 'faktura_skapad';
    }
    if (trim((string) ($row['fortnox_order'] ?? '')) !== '') {
        return 'order_skapad';
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    if (trim((string) ($steps['faktura_skapad'] ?? '')) !== '') {
        return 'faktura_skapad';
    }
    if (trim((string) ($steps['order_skriven'] ?? '')) !== '') {
        return 'order_skapad';
    }
    return '';
}

function jeab_ordrar_fakturera_statuses(): array
{
    return [
        'order_skapad' => 'Order skapad',
        'faktura_skapad' => 'Faktura skapad',
    ];
}

function jeab_ordrar_fakturera_label(array $row): string
{
    $status = jeab_ordrar_fakturera_status($row);
    $labels = jeab_ordrar_fakturera_statuses();
    return $labels[$status] ?? '';
}

function jeab_ordrar_fakturera_tone(array $row): string
{
    return match (jeab_ordrar_fakturera_status($row)) {
        'faktura_skapad' => 'ok',
        'order_skapad' => 'warn',
        default => 'muted',
    };
}


function jeab_ordrar_bestallning_choices(): array
{
    return [
        'A' => 'Automatisk',
        'B' => 'Spara som utkast',
        'C' => 'Manuell/ingen',
    ];
}

function jeab_ordrar_pencil_svg(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zm17.71-10.04a1.003 1.003 0 0 0 0-1.42l-2.5-2.5a1.003 1.003 0 0 0-1.42 0l-1.83 1.83 3.75 3.75 1.999-1.66z"/></svg>';
}

function jeab_ordrar_normalize_bestallning_status(string $raw): string
{
    $raw = strtoupper(trim($raw));
    return in_array($raw, ['A', 'B', 'C'], true) ? $raw : '';
}

function jeab_ordrar_bestallning_status(array $row): string
{
    return jeab_ordrar_normalize_bestallning_status((string) ($row['bestallning_status'] ?? ''));
}

function jeab_ordrar_apply_bestallning_status(array $row, array $input): array
{
    if (array_key_exists('bestallning_status', $input)) {
        $row['bestallning_status'] = jeab_ordrar_normalize_bestallning_status((string) $input['bestallning_status']);
    }
    return $row;
}

function jeab_ordrar_ymd(?string $raw): string
{
    $day = jeab_ordrar_day($raw);
    return $day instanceof DateTimeImmutable ? $day->format('Y-m-d') : '';
}

function jeab_ordrar_suggest_install_ymd(array $row, ?JeabStore $store = null): string
{
    $booked = jeab_ordrar_ymd(jeab_ordrar_install_start($row, $store));
    if ($booked !== '') {
        return $booked;
    }
    $manual = jeab_ordrar_ymd((string) ($row['install_date'] ?? $row['install_ymd'] ?? ''));
    if ($manual !== '') {
        return $manual;
    }
    if (!$store instanceof JeabStore) {
        $store = jeab_ordrar_try_store();
    }
    if (!$store instanceof JeabStore) {
        $fallback = jeab_ordrar_book_earliest_day((string) (jeab_purchase_normalize_kind($row['kind'] ?? '') ?? ''));
        while (!jeab_ordrar_is_workday($fallback)) {
            $fallback = $fallback->modify('+1 day');
        }
        return $fallback->format('Y-m-d');
    }
    $kind = jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '';
    $companyHint = jeab_normalize_company($row['company'] ?? null);
    $who = jeab_ordrar_book_who($kind, $companyHint);
    if ($who === '') {
        return '';
    }
    try {
        $slot = jeab_ordrar_first_free_slot($store, $kind, $who, $row['company'] ?? null, null, null, $companyHint === 'malmo');
        return jeab_ordrar_ymd((string) ($slot['starts_at'] ?? ''));
    } catch (Throwable) {
        return '';
    }
}

function jeab_ordrar_suggest_delivery_ymd(array $row, ?JeabStore $store = null, ?string $installYmd = null): string
{
    $install = jeab_ordrar_ymd($installYmd) !== '' ? jeab_ordrar_ymd($installYmd) : jeab_ordrar_suggest_install_ymd($row, $store);
    if ($install !== '') {
        $computed = jeab_ordrar_wanted_delivery_ymd($install, (string) ($row['kind'] ?? ''));
        if ($computed !== '') {
            return $computed;
        }
    }
    return jeab_ordrar_ymd((string) ($row['onskad_leverans'] ?? $row['önskad_leverans'] ?? ''));
}

/**
 * Batteri och sol bokar kalendern i Beställ. Laddbox bokas i Bekräfta.
 */
function jeab_ordrar_books_at_bestall(array $row): bool
{
    $kind = jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '';

    return $kind === 'batteri' || $kind === 'sol';
}

/**
 * Önskad leverans räknas bakåt från installationen.
 * Laddbox och batteri: 2–3 dagar, standard 3. Sol: 4–6 dagar, standard 6.
 * Helg och röd dag inom spannet flyttas mot installationen till en arbetsdag.
 *
 * @return array{min:int,max:int,default:int}
 */
function jeab_ordrar_delivery_window(string $kind): array
{
    $kind = jeab_purchase_normalize_kind($kind) ?? $kind;
    if ($kind === 'sol') {
        return ['min' => 4, 'max' => 6, 'default' => 6];
    }

    return ['min' => 2, 'max' => 3, 'default' => 3];
}

function jeab_ordrar_wanted_delivery_ymd(string $installYmd, string $kind): string
{
    $install = jeab_ordrar_day($installYmd);
    if (!$install instanceof DateTimeImmutable) {
        return '';
    }
    $window = jeab_ordrar_delivery_window($kind);
    $pick = (int) $window['default'];
    $day = $install->modify('-' . $pick . ' days');
    if (!jeab_ordrar_is_workday($day)) {
        for ($d = $pick - 1; $d >= (int) $window['min']; $d--) {
            $try = $install->modify('-' . $d . ' days');
            if (jeab_ordrar_is_workday($try)) {
                return $try->format('Y-m-d');
            }
        }
    }

    return $day->format('Y-m-d');
}

function jeab_ordrar_delivery_lead_help(string $kind): string
{
    $n = (int) jeab_ordrar_delivery_window($kind)['default'];

    return 'Önskad leverans är ' . $n . ' dagar före installationen.';
}

/**
 * Tid och önskad leverans som Beställ visar. Skriver ingen bokning.
 * Redan bokad order behåller sin tid. Leveransdatumet räknas från den.
 *
 * @return array{booked:bool,starts_at:string,ends_at:string,delivery:string,who:list<string>,kind:string}|null
 */
function jeab_ordrar_install_proposal(JeabStore $store, array $row): ?array
{
    $kind = jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '';
    if (!in_array($kind, ['laddbox', 'batteri', 'sol'], true)) {
        return null;
    }
    if (jeab_ordrar_is_booked($row, $store)) {
        $times = jeab_ordrar_booking_times($row, $store);
        $start = (string) ($times['starts_at'] ?? '');
        if ($start === '') {
            return null;
        }

        return [
            'booked' => true,
            'starts_at' => $start,
            'ends_at' => (string) ($times['ends_at'] ?? ''),
            'delivery' => jeab_ordrar_wanted_delivery_ymd($start, $kind),
            'who' => [],
            'kind' => $kind,
        ];
    }
    $slot = jeab_ordrar_proposed_slot($store, $row);
    if ($slot === null) {
        return null;
    }

    return [
        'booked' => false,
        'starts_at' => (string) $slot['starts_at'],
        'ends_at' => (string) $slot['ends_at'],
        'delivery' => jeab_ordrar_wanted_delivery_ymd((string) $slot['starts_at'], $kind),
        'who' => is_array($slot['who'] ?? null) ? array_values($slot['who']) : [],
        'kind' => $kind,
    ];
}

function jeab_ordrar_proposal_when_label(array $slot): string
{
    $text = jeab_ordrar_proposed_slot_text($slot);

    return preg_replace('/^Föreslagen tid:\s*/u', '', $text) ?? $text;
}

/**
 * Bokar bara när det postade förslaget fortfarande är samma lucka.
 * Tomt förslag bokar inte (äldre anrop och Spara). Avvikelse kastar innan något skrivs till kalendern.
 *
 * @param array<string, mixed> $input
 * @return array<string, string>
 */
function jeab_ordrar_proposal_book_input(JeabStore $store, array $row, array $input): array
{
    if (jeab_ordrar_is_booked($row, $store) || !jeab_ordrar_books_at_bestall($row)) {
        return [];
    }
    $posted = trim((string) ($input['proposal_starts'] ?? ''));
    if ($posted === '') {
        return [];
    }
    $fresh = jeab_ordrar_proposed_slot($store, $row);
    $freshStart = is_array($fresh) ? (string) ($fresh['starts_at'] ?? '') : '';
    $freshEnd = is_array($fresh) ? (string) ($fresh['ends_at'] ?? '') : '';
    $postedEnd = trim((string) ($input['proposal_ends'] ?? ''));
    if ($freshStart === '' || $freshStart !== $posted || ($postedEnd !== '' && $postedEnd !== $freshEnd)) {
        throw new InvalidArgumentException('Förslaget har ändrats. Ladda om Beställ och bekräfta den nya tiden.');
    }

    return [
        'book_confirmed' => '1',
        'keep_slot' => '1',
        'proposal_starts' => $freshStart,
        'proposal_ends' => $freshEnd,
        'install_date' => jeab_ordrar_ymd($freshStart),
    ];
}

function jeab_ordrar_proposal_hidden(array $proposal): string
{
    $delivery = (string) ($proposal['delivery'] ?? '');
    if (!empty($proposal['booked'])) {
        return '<input type="hidden" name="onskad_leverans" value="' . e($delivery) . '">';
    }

    return '<input type="hidden" name="proposal_starts" value="' . e((string) ($proposal['starts_at'] ?? '')) . '">'
        . '<input type="hidden" name="proposal_ends" value="' . e((string) ($proposal['ends_at'] ?? '')) . '">'
        . '<input type="hidden" name="install_date" value="' . e(jeab_ordrar_ymd((string) ($proposal['starts_at'] ?? ''))) . '">'
        . '<input type="hidden" name="onskad_leverans" value="' . e($delivery) . '">';
}

/** Förslaget i Beställ. Inget datumfält. Ingen bokning. */
function jeab_ordrar_proposal_block_html(?array $proposal): string
{
    if ($proposal === null || trim((string) ($proposal['starts_at'] ?? '')) === '') {
        return '<p class="v3-help2" data-install-proposal="missing">Ingen ledig installationstid hittades. Inget bokas förrän en tid kan visas.</p>';
    }
    $delivery = (string) ($proposal['delivery'] ?? '');
    $kind = (string) ($proposal['kind'] ?? '');
    $stamp = e((string) $proposal['starts_at'] . '|' . (string) ($proposal['ends_at'] ?? ''));
    $when = jeab_ordrar_proposal_when_label($proposal);
    if (!empty($proposal['booked'])) {
        $body = '<p class="v3-help2" data-install-proposal="booked" data-proposed-slot="' . $stamp . '">Bokad installation: '
            . e($when) . '. Befintlig tid behålls och bokas inte om.</p>';
    } else {
        $body = '<p class="v3-help2" data-install-proposal="open" data-proposed-slot="' . $stamp . '">'
            . e(jeab_ordrar_proposed_slot_text($proposal)) . '</p>'
            . '<p class="v3-help">' . e(jeab_ordrar_book_lead_help($kind)) . '</p>';
    }
    $body .= '<p class="v3-help2" data-wanted-delivery="' . e($delivery) . '">Önskad leverans: '
        . e($delivery !== '' ? $delivery : '–')
        . '. ' . e(jeab_ordrar_delivery_lead_help($kind)) . ' Datumet går inte att ändra.</p>';

    return '<div class="v3-proposal" data-install-proposal>' . $body . jeab_ordrar_proposal_hidden($proposal) . '</div>';
}

function jeab_ordrar_sol_day_count(): int
{
    return 5;
}

/**
 * Solceller bokas alltid som heldagar, fem arbetsdagar från startdagen.
 *
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_sol_span_from_day(DateTimeImmutable $day): array
{
    $day = $day->setTime(0, 0, 0);
    while ((int) $day->format('N') >= 6) {
        $day = $day->modify('+1 day');
    }
    $starts = $day->format('Y-m-d') . ' 00:00:00';
    $ends = jeab_booking_add_weekdays($starts, jeab_ordrar_sol_day_count());
    return ['starts_at' => $starts, 'ends_at' => $ends];
}

/**
 * Första lediga femdagarsblocket från och med $startDay.
 *
 * @param list<array<string,mixed>> $busy
 * @param list<array<string,mixed>> $outlook
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_pick_sol_span(array $busy, array $outlook, string $who, DateTimeImmutable $startDay): array
{
    $startDay = $startDay->setTime(0, 0, 0);
    for ($i = 0; $i < 180; $i++) {
        $day = $startDay->modify('+' . $i . ' days');
        if ((int) $day->format('N') >= 6) {
            continue;
        }
        $span = jeab_ordrar_sol_span_from_day($day);
        if (jeab_ordrar_slot_busy($busy, $who, $span['starts_at'], $span['ends_at'])) {
            continue;
        }
        if (jeab_ordrar_outlook_slot_busy($outlook, $span['starts_at'], $span['ends_at'])) {
            continue;
        }
        return $span;
    }
    throw new InvalidArgumentException('Ingen ledig arbetsvecka hittades.');
}

/**
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_sol_span_search(JeabStore $store, string $who, DateTimeImmutable $startDay, ?string $company = null): array
{
    $lookFrom = $startDay->modify('-14 days');
    $lookTo = $startDay->modify('+180 days');
    $busy = $store->bookingsBetween($lookFrom->format('Y-m-d H:i:s'), $lookTo->format('Y-m-d H:i:s'));
    $outlook = (jeab_ordrar_external_live() && function_exists('jeab_gp_busy_for_person'))
        ? jeab_gp_busy_for_person($who, $lookFrom, $lookTo, $company)
        : [];
    return jeab_ordrar_pick_sol_span($busy, $outlook, $who, $startDay);
}

/**
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_first_free_sol_days(JeabStore $store, string $who, int $minDays, ?string $company = null): array
{
    $tz = new DateTimeZone('Europe/Stockholm');
    $startDay = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0)->modify('+' . max(0, $minDays) . ' days');
    return jeab_ordrar_sol_span_search($store, $who, $startDay, $company);
}

/**
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_first_free_sol_days_on_or_after(JeabStore $store, string $who, string $fromYmd, ?string $company = null): array
{
    $startDay = jeab_ordrar_day($fromYmd);
    if (!$startDay instanceof DateTimeImmutable) {
        throw new InvalidArgumentException('Ogiltigt datum.');
    }
    $tz = new DateTimeZone('Europe/Stockholm');
    $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
    if ($startDay < $today) {
        $startDay = $today;
    }
    return jeab_ordrar_sol_span_search($store, $who, $startDay, $company);
}

/**
 * Tidsfönster för en dagsbokning.
 * Batteri är heldag 07:00–16:00 (9 h). Laddbox är halvdag 07:00–11:00 eller 12:00–16:00.
 * Befintliga bokningar (t.ex. batteri 07–11) skrivs inte om.
 *
 * @return list<array{0:string,1:string}>
 */
function jeab_ordrar_day_windows(string $kind): array
{
    if ((jeab_purchase_normalize_kind($kind) ?? $kind) === 'batteri') {
        return [['07:00:00', '16:00:00']];
    }
    return [['07:00:00', '11:00:00'], ['12:00:00', '16:00:00']];
}

/** HH:MM, or empty when the clock is missing or invalid. */
function jeab_ordrar_install_clock(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^(\d{1,2}):(\d{2})/', $raw, $m) !== 1) {
        return '';
    }
    $hour = (int) $m[1];
    $minute = (int) $m[2];
    if ($hour > 23 || $minute > 59) {
        return '';
    }

    return sprintf('%02d:%02d', $hour, $minute);
}

/**
 * A clock at 12:00 or later prefers the afternoon window when the day has two.
 * Batteri stays one full day. An empty clock keeps the morning window first.
 *
 * @param list<array{0:string,1:string}> $windows
 * @return list<array{0:string,1:string}>
 */
function jeab_ordrar_windows_for_clock(array $windows, string $clock): array
{
    $clock = jeab_ordrar_install_clock($clock);
    if ($clock === '' || count($windows) < 2 || strcmp($clock, '12:00') < 0) {
        return $windows;
    }

    return array_reverse($windows);
}

/**
 * @param list<array{0:string,1:string}> $windows
 * @param list<array<string,mixed>> $busy
 * @param list<array<string,mixed>> $outlook
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_pick_day_window(array $busy, array $outlook, string $who, DateTimeImmutable $startDay, array $windows, string $emptyMessage): array
{
    $startDay = $startDay->setTime(0, 0, 0);
    for ($i = 0; $i < 180; $i++) {
        $day = $startDay->modify('+' . $i . ' days');
        if (!jeab_ordrar_is_workday($day)) {
            continue;
        }
        $date = $day->format('Y-m-d');
        foreach ($windows as [$fromClock, $toClock]) {
            $slotStart = $date . ' ' . $fromClock;
            $slotEnd = $date . ' ' . $toClock;
            if (jeab_ordrar_slot_busy($busy, $who, $slotStart, $slotEnd)) {
                continue;
            }
            if (jeab_ordrar_outlook_slot_busy($outlook, $slotStart, $slotEnd)) {
                continue;
            }
            return ['starts_at' => $slotStart, 'ends_at' => $slotEnd];
        }
    }
    throw new InvalidArgumentException($emptyMessage);
}

/**
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_search_day_window(JeabStore $store, string $who, DateTimeImmutable $startDay, string $kind, ?string $company = null, string $clock = ''): array
{
    $kind = (jeab_purchase_normalize_kind($kind) ?? '') === 'batteri' ? 'batteri' : 'laddbox';
    $lookFrom = $startDay->modify('-14 days');
    $lookTo = $startDay->modify('+180 days');
    $busy = $store->bookingsBetween($lookFrom->format('Y-m-d H:i:s'), $lookTo->format('Y-m-d H:i:s'));
    $outlook = (jeab_ordrar_external_live() && function_exists('jeab_gp_busy_for_person'))
        ? jeab_gp_busy_for_person($who, $lookFrom, $lookTo, $company)
        : [];
    $empty = $kind === 'batteri' ? 'Ingen ledig heldag hittades.' : 'Ingen ledig halvdag hittades.';
    $windows = jeab_ordrar_windows_for_clock(jeab_ordrar_day_windows($kind), $clock);

    return jeab_ordrar_pick_day_window($busy, $outlook, $who, $startDay, $windows, $empty);
}

function jeab_ordrar_first_free_halfday_on_or_after(JeabStore $store, string $who, string $fromYmd, ?string $company = null, string $kind = 'laddbox', string $clock = ''): array
{
    $ymd = jeab_ordrar_ymd($fromYmd);
    if ($ymd === '') {
        throw new InvalidArgumentException('Ogiltigt datum.');
    }
    $tz = new DateTimeZone('Europe/Stockholm');
    $startDay = (new DateTimeImmutable($ymd . ' 00:00:00', $tz));
    $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
    if ($startDay < $today) {
        $startDay = $today;
    }
    return jeab_ordrar_search_day_window($store, $who, $startDay, $kind, $company, $clock);
}

function jeab_ordrar_spec_html(array $open, string $company, int $openId, ?string $error = null): string
{
    if (function_exists('jeab_ordrar_display_row')) {
        $open = jeab_ordrar_display_row($open);
    }
    $kind = jeab_ordrar_ui_kind($open['kind'] ?? '');
    $labels = ['laddbox' => 'Elbilsladdare', 'batteri' => 'Batteri', 'sol' => 'Solpaneler'];
    $kindLabel = $labels[$kind] ?? '';
    $address = trim((string) ($open['huvudsokande_address'] ?? $open['address'] ?? ''));
    $vaxel = trim((string) ($open['vaxelriktare'] ?? ''));
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'spec']);
    $foranStatus = jeab_ordrar_foranmalan_status($open);
    if ($foranStatus === '') {
        $foranStatus = 'A';
    }
    $foranChoices = jeab_ordrar_foranmalan_choices();
    $natbolag = jeab_ordrar_natbolag($open);
    $formId = 'ordrar-spec-form';
    ob_start();
    ?>
    <div class="ordrar-spec" data-view="spec">
        <div class="ordrar-card">
            <form id="<?= e($formId) ?>" data-js="ordrar-ajax" method="post" action="<?= e($action) ?>">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="ordrar.batterisol">
                <input type="hidden" name="id" value="<?= (int) $openId ?>">
                <h2>Följdfrågor</h2>
                <?php if ($address !== ''): ?>
                    <p class="ordrar-help">Leveransadress: <?= e($address) ?></p>
                <?php endif; ?>
                <label class="ordrar-field">
                    <span>Växelriktare</span>
                    <input type="text" name="vaxelriktare" value="<?= e($vaxel) ?>" placeholder="Välj växelriktare" autocomplete="off">
                </label>
                <label class="ordrar-field">
                    <span>kWh</span>
                    <input type="text" name="kwh" value="<?= e((string) ($open['kwh'] ?? '')) ?>" autocomplete="off">
                </label>
                <label class="ordrar-field">
                    <span>Anläggnings-id</span>
                    <input type="text" name="anlaggnings_id" inputmode="numeric" spellcheck="false" autocomplete="off" data-js="anlaggnings-id"<?= $natbolag === 'SEOM' ? ' required aria-required="true"' : '' ?> value="<?= e(jeab_ordrar_anlaggnings_id_digits((string) ($open['anlaggnings_id'] ?? ''))) ?>">
                </label>
                <fieldset class="ordrar-natbolag">
                    <legend>Nätbolag</legend>
                    <?php if ($natbolag === ''): ?>
                        <p class="ordrar-help">Ingen säker träff. Välj nätbolag själv.</p>
                    <?php endif; ?>
                    <div class="chips ordrar-kind ordrar-natbolag-choices" role="radiogroup" aria-label="Nätbolag">
                        <?php foreach (jeab_ordrar_natbolag_options() as $nat): ?>
                            <label class="chip-opt">
                                <input type="radio" name="natbolag" value="<?= e($nat) ?>"<?= $natbolag === $nat ? ' checked' : '' ?>>
                                <span><?= e($nat) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <fieldset class="ordrar-foranmalan">
                    <legend>Föranmälan</legend>
                    <?php if ($natbolag === 'SEOM'): ?>
                        <p class="ordrar-help">Nästa skapar SEOM-blanketten och mejlar den till Sam. Anläggnings-id krävs. Kunden får den inte automatiskt.</p>
                    <?php else: ?>
                        <p class="ordrar-help">Nästa med Automatiskt skickar föranmälan till Bokmalen.</p>
                        <p class="ordrar-help">Inget skickas förrän du trycker. Det skickas direkt när du trycker.</p>
                    <?php endif; ?>
                    <div class="chips ordrar-foranmalan-choices" role="radiogroup" aria-label="Föranmälan">
                        <?php foreach ($foranChoices as $value => $label): ?>
                            <label class="chip-opt">
                                <input type="radio" name="foranmalan_status" value="<?= e($value) ?>"<?= $foranStatus === $value ? ' checked' : '' ?>>
                                <span><?= e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </form>
        </div>
        <div class="ordrar-next-row ordrar-spec-actions">
            <button class="ordrar-secondary" form="<?= e($formId) ?>" type="submit">Spara</button>
            <button class="ordrar-primary" form="<?= e($formId) ?>" type="submit" name="intent" value="next" data-js="ordrar-nasta">Nästa</button>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function jeab_ordrar_ajax_nasta_payload(array $row, string $flash = ''): array
{
    $company = (string) ($row['company'] ?? (function_exists('jeab_selected_company') ? jeab_selected_company() : 'jarfalla'));
    $id = (int) ($row['reminder_id'] ?? 0);
    $status = $flash !== '' ? $flash : 'Sparad.';
    $stop = jeab_ordrar_is_solbatteri($row) ? 'spec' : 'bekrafta';
    $view = jeab_ordrar_pane_html($row, $company, $id, $stop);
    return [
        'result_html' => jeab_ordrar_result_body($row, $status),
        'view_html' => $view,
        'spec_html' => $view,
        'stepper_html' => jeab_ordrar_stepper_html($row, $company, $id, $stop),
        'stop' => $stop,
        'stop_url' => jeab_ordrar_url(['co' => $company, 'id' => $id, 'stop' => $stop]),
        'flow' => jeab_ordrar_flow($row),
        'status' => $status,
        'kind' => jeab_ordrar_ui_kind($row['kind'] ?? ''),
    ];
}

function jeab_ordrar_ajax_kind_payload(array $row, string $stop, string $flash = ''): array
{
    $company = (string) ($row['company'] ?? (function_exists('jeab_selected_company') ? jeab_selected_company() : 'jarfalla'));
    $id = (int) ($row['reminder_id'] ?? 0);
    $stop = $stop !== '' ? $stop : 'bekrafta';
    $status = $flash !== '' ? $flash : 'Sparad.';
    return [
        'result_html' => jeab_ordrar_result_body($row, $status),
        'stepper_html' => jeab_ordrar_stepper_html($row, $company, $id, $stop),
        'flow' => jeab_ordrar_flow($row),
        'stop' => $stop,
        'stop_url' => jeab_ordrar_url(['co' => $company, 'id' => $id, 'stop' => $stop]),
        'kind' => jeab_ordrar_ui_kind($row['kind'] ?? ''),
        'status' => $status,
    ];
}

function jeab_ordrar_stepper_html(array $open, string $company, int $openId, string $view): string
{
    $flow = jeab_ordrar_flow($open);
    $currentKey = $view;
    if ($currentKey === 'bestall' || $currentKey === 'boka' || $currentKey === 'boka_bestall') {
        $currentKey = 'orderrader';
    }
    ob_start();
    ?>
    <ol class="ordrar-stepper">
        <li class="ordrar-step is-status<?= $view === 'status' ? ' is-current' : '' ?>" data-key="status">
            <a class="ordrar-step-link ordrar-step-row" href="<?= e(jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'status'])) ?>">
                <span class="ordrar-step-lbl">Status</span>
                <span class="ordrar-step-date"></span>
            </a>
        </li>
        <?php $navN = 0; ?>
        <?php foreach ($flow as $step): ?>
            <?php
            $stepKey = (string) ($step['key'] ?? '');
            if (in_array($stepKey, ['boka', 'bestall'], true)) {
                continue;
            }
            $navN++;
            $stepPair = trim((string) ($step['pair'] ?? ''));
            $stepClick = jeab_ordrar_stop_clickable($open, $stepKey);
            $stepHref = $stepClick ? jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => $stepKey]) : '';
            $stepCurrent = $stepKey === $currentKey || $stepKey === $view;
            ?>
            <li class="ordrar-step<?= $stepCurrent ? ' is-current' : '' ?><?= !empty($step['done']) ? ' is-done' : '' ?><?= $stepPair !== '' ? ' is-parallel' : '' ?>" data-key="<?= e($stepKey) ?>"<?= $stepPair !== '' ? ' data-pair="' . e($stepPair) . '"' : '' ?>>
                <?php if ($stepClick): ?>
                    <a class="ordrar-step-link ordrar-step-row" href="<?= e($stepHref) ?>">
                        <span class="ordrar-step-n"><?= $navN ?></span>
                        <span class="ordrar-step-lbl"><?= e((string) $step['label']) ?></span>
                        <span class="ordrar-step-date"><?= e((string) ($step['date'] ?? '')) ?></span>
                    </a>
                <?php else: ?>
                    <span class="ordrar-step-row">
                        <span class="ordrar-step-n"><?= $navN ?></span>
                        <span class="ordrar-step-lbl"><?= e((string) $step['label']) ?></span>
                        <span class="ordrar-step-date"><?= e((string) ($step['date'] ?? '')) ?></span>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php
    return (string) ob_get_clean();
}

function jeab_ordrar_try_store(): ?JeabStore
{
    try {
        $maybe = function_exists('jeab_store') ? jeab_store() : null;
        return $maybe instanceof JeabStore ? $maybe : null;
    } catch (Throwable) {
        return null;
    }
}

function jeab_ordrar_flow_numbers(array $flow): array
{
    $out = [];
    $n = 0;
    $pairN = [];
    foreach ($flow as $i => $step) {
        $pair = is_array($step) ? trim((string) ($step['pair'] ?? '')) : '';
        if ($pair !== '' && isset($pairN[$pair])) {
            $out[$i] = $pairN[$pair];
            continue;
        }
        $n++;
        $out[$i] = $n;
        if ($pair !== '') {
            $pairN[$pair] = $n;
        }
    }
    return $out;
}

function jeab_ordrar_dot_tone_for_step(array $row, string $key): string
{
    if ($key === 'eon') {
        $tone = jeab_ordrar_foranmalan_case_tone($row);
        return in_array($tone, ['danger', 'warn', 'ok'], true) ? $tone : '';
    }
    if ($key === 'fardiganmal' || $key === 'fakturering') {
        $tone = jeab_ordrar_fakturera_tone($row);
        return in_array($tone, ['danger', 'warn', 'ok'], true) ? $tone : '';
    }
    return '';
}

function jeab_ordrar_dots_html(array $row, array $flow): string
{
    $stepDone = 0;
    $stepIdx = 0;
    foreach ($flow as $i => $st) {
        if (!empty($st['done'])) {
            $stepDone++;
        }
        if (!empty($st['current'])) {
            $stepIdx = $i + 1;
        }
    }
    if (jeab_ordrar_allt_klart($row)) {
        // Stängt ärende: alla prickar klara, ingen «nu»-prick (samma källa som Process-kolumnen).
        $stepDone = count($flow);
        $stepIdx = -1;
    } elseif ($stepIdx === 0) {
        $stepIdx = min($stepDone + 1, max(1, count($flow)));
    }

    $classes = [];
    $lastGreen = 0;
    foreach ($flow as $i => $st) {
        $d = $i + 1;
        $key = trim((string) ($st['key'] ?? ''));
        $tone = jeab_ordrar_dot_tone_for_step($row, $key);
        $done = $d <= $stepDone;
        $now = $d === $stepIdx;
        $cls = ['ordrar-dot'];
        if ($tone === 'danger') {
            $cls[] = 'is-danger';
            $cls[] = 'is-lit';
        } elseif ($tone === 'warn') {
            $cls[] = 'is-warn';
            $cls[] = 'is-lit';
        } elseif ($tone === 'ok') {
            $cls[] = 'is-ok';
            $cls[] = 'is-lit';
            $lastGreen = $d;
        } elseif ($done) {
            $cls[] = 'is-done';
            $lastGreen = $d;
        } elseif ($now) {
            $cls[] = 'is-now';
            $cls[] = 'is-lit';
            $lastGreen = $d;
        }
        $classes[$d] = $cls;
    }
    if ($lastGreen > 0 && isset($classes[$lastGreen]) && !in_array('is-lit', $classes[$lastGreen], true)) {
        $classes[$lastGreen][] = 'is-lit';
    }

    $html = '<span class="ordrar-dots" aria-hidden="true">';
    foreach ($classes as $cls) {
        $html .= '<i class="' . e(implode(' ', $cls)) . '"></i>';
    }
    return $html . '</span>';
}

function jeab_ordrar_pane_html(array $open, string $company, int $openId, string $stop, ?string $error = null, mixed $store = null): string
{
    $open = jeab_ordrar_display_row($open);
    $store = $store instanceof JeabStore ? $store : jeab_ordrar_try_store();
    return match ($stop) {
        'status' => jeab_ordrar_status_html($open, $company, $openId, $store),
        'vad' => jeab_ordrar_vad_html($open, $company, $openId),
        'spec' => jeab_ordrar_spec_html($open, $company, $openId, $error),
        'boka_bestall' => jeab_ordrar_boka_bestall_html($open, $company, $openId, $store, $error),
        'bestall' => jeab_ordrar_bestall_step_html($open, $company, $openId, $store, $error),
        'boka', 'boka_elin' => jeab_ordrar_boka_html($open, $company, $openId, $stop, $store),
        'eon' => jeab_ordrar_eon_html($open, $company, $openId, $error),
        'fakturering' => jeab_ordrar_fakturering_html($open, $company, $openId, $error),
        'paminelse', 'kop', 'fardiganmal', 'fakturera' => jeab_ordrar_solo_html($openId, $stop, $company),
        default => '',
    };
}

function jeab_ordrar_ajax_stop_payload(array $row, string $stop, string $flash = ''): array
{
    $company = (string) ($row['company'] ?? (function_exists('jeab_selected_company') ? jeab_selected_company() : 'jarfalla'));
    $id = (int) ($row['reminder_id'] ?? 0);
    $status = $flash !== '' ? $flash : 'Sparad.';
    return [
        'result_html' => jeab_ordrar_result_body($row, $status),
        'view_html' => jeab_ordrar_pane_html($row, $company, $id, $stop),
        'stop' => $stop,
        'stop_url' => jeab_ordrar_url(['co' => $company, 'id' => $id, 'stop' => $stop]),
        'flow' => jeab_ordrar_flow($row),
        'status' => $status,
        'kind' => jeab_ordrar_ui_kind($row['kind'] ?? ''),
    ];
}

function jeab_ordrar_boka_html(array $open, string $company, int $openId, string $view = 'boka', mixed $store = null): string
{
    $open = jeab_ordrar_display_row($open);
    $address = trim((string) ($open['huvudsokande_address'] ?? $open['address'] ?? ''));
    $solar = $view === 'boka';
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => $view]);
    $store = $store instanceof JeabStore ? $store : jeab_ordrar_try_store();
    ob_start();
    ?>
    <div class="ordrar-card" data-js="ordrar-pane" data-stop="<?= e($view) ?>">
        <h2><?= $view === 'boka_elin' ? 'Boka Elin' : 'Boka' ?></h2>
        <?php if ($address !== ''): ?>
            <p class="ordrar-help">Leveransadress: <?= e($address) ?></p>
        <?php endif; ?>
        <?php if ($solar): ?>
            <p class="ordrar-help"><?= e(jeab_ordrar_book_lead_help((string) ($open['kind'] ?? ''))) ?> Nästa steg är Beställ.</p>
        <?php endif; ?>
        <div class="ordrar-result" data-js="ordrar-result" aria-live="polite">
            <?php if (jeab_ordrar_is_booked($open)): ?>
                <p class="ordrar-help">Bokad tid: <?= e(jeab_ordrar_mmdd(jeab_ordrar_install_start($open, $store))) ?></p>
                <?php if ($solar): ?>
                    <form data-js="ordrar-ajax" method="post" action="<?= e($action) ?>">
                        <?= jeab_csrf_field() ?>
                        <input type="hidden" name="action" value="ordrar.advance">
                        <input type="hidden" name="from" value="boka">
                        <input type="hidden" name="id" value="<?= (int) $openId ?>">
                        <button class="ordrar-primary" type="submit" data-js="ordrar-nasta">Nästa</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <?php $installYmd = $solar ? jeab_ordrar_suggest_install_ymd($open, $store) : ''; ?>
                <form<?= $solar ? ' data-js="ordrar-ajax"' : '' ?> method="post" action="<?= e($action) ?>">
                    <?= jeab_csrf_field() ?>
                    <input type="hidden" name="action" value="ordrar.boka">
                    <input type="hidden" name="id" value="<?= (int) $openId ?>">
                    <?php if ($solar): ?>
                        <label class="ordrar-field">
                            <span>Föreslagen installation</span>
                            <input type="date" name="install_date" value="<?= e($installYmd) ?>">
                        </label>
                    <?php endif; ?>
                    <button class="ordrar-primary" type="submit">Boka</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function jeab_ordrar_save_fail_text(string $reason): string
{
    $reason = trim($reason);
    if ($reason === '') {
        return 'Kunde inte spara.';
    }
    if (preg_match('/^Kunde inte spara\b/u', $reason) === 1) {
        return $reason;
    }
    return 'Kunde inte spara. ' . $reason;
}

function jeab_ordrar_save_fail_html(?string $error): string
{
    if (!is_string($error) || trim($error) === '') {
        return '';
    }
    return '<p class="ordrar-help is-alert" role="alert" data-js="ordrar-ajax-error">'
        . e(jeab_ordrar_save_fail_text($error))
        . '</p>';
}

function jeab_ordrar_bokmalen_help_html(array $row, string $type, ?string $error = null): string
{
    if (is_string($error) && str_contains($error, 'Saknas för Bokmalen')) {
        return '';
    }
    $missing = function_exists('jeab_ordrar_bokmalen_missing')
        ? jeab_ordrar_bokmalen_missing($row, $type)
        : [];
    if ($missing === []) {
        return '';
    }
    return '<p class="ordrar-help" data-js="ordrar-missing-preview">Saknas för Bokmalen: ' . e(implode(', ', $missing)) . '.</p>';
}

function jeab_ordrar_bestall_html(array $open, string $company, int $openId, mixed $store = null, ?string $error = null): string
{
    return jeab_ordrar_bestall_step_html($open, $company, $openId, $store, $error);
}

function jeab_ordrar_boka_bestall_html(array $open, string $company, int $openId, mixed $store = null, ?string $error = null): string
{
    $store = $store instanceof JeabStore ? $store : jeab_ordrar_try_store();
    $alreadyBestalld = jeab_ordrar_bestalld($open);
    $booked = jeab_ordrar_is_booked($open, $store);
    $proposal = $store instanceof JeabStore ? jeab_ordrar_install_proposal($store, $open) : null;
    $status = jeab_ordrar_bestallning_status($open);
    if ($status === '') {
        $status = 'A';
    }
    $choices = jeab_ordrar_bestallning_choices();
    $shopLabel = jeab_ordrar_shop_label($open);
    $bestalldAt = trim((string) ($open['steps']['bestalld'] ?? ''));
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'boka_bestall']);
    $bokmalenHelp = jeab_ordrar_bokmalen_help_html($open, 'ordrar.bestall', $error);
    $formId = 'ordrar-boka-bestall-form';
    $done = $booked && $alreadyBestalld;
    ob_start();
    ?>
    <div class="ordrar-boka-bestall" data-view="boka_bestall" data-js="ordrar-pane" data-stop="boka_bestall">
        <div class="ordrar-card">
            <form id="<?= e($formId) ?>" data-js="ordrar-ajax" method="post" action="<?= e($action) ?>">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="ordrar.boka_bestall">
                <input type="hidden" name="id" value="<?= (int) $openId ?>">
                <input type="hidden" name="intent" value="" data-js="intent-mirror">
                <h2>Boka+Beställ</h2>
                <p class="ordrar-help"><?= e(jeab_ordrar_book_lead_help((string) ($open['kind'] ?? ''))) ?> Önskad leverans följer installationstiden.</p>
                <?= jeab_ordrar_proposal_block_html($proposal) ?>

                <?php if ($booked): ?>
                    <p class="ordrar-help">Bokad installation: <?= e(jeab_ordrar_mmdd(jeab_ordrar_install_start($open, $store))) ?></p>
                <?php endif; ?>
                <?php if ($bestalldAt !== ''): ?>
                    <p class="ordrar-help">Beställd <?= e(jeab_ordrar_mmdd($bestalldAt)) ?>.</p>
                <?php endif; ?>
                <?php if ($shopLabel !== ''): ?>
                    <p class="ordrar-help ordrar-shop"><?= e($shopLabel) ?></p>
                <?php endif; ?>
                <?= $bokmalenHelp ?>

                <fieldset class="ordrar-foranmalan ordrar-bestallning">
                    <legend>Beställning</legend>
                    <div class="chips ordrar-foranmalan-choices" role="radiogroup" aria-label="Beställning">
                        <?php foreach ($choices as $value => $label): ?>
                            <label class="chip-opt">
                                <input type="radio" name="bestallning_status" value="<?= e($value) ?>"<?= $status === $value ? ' checked' : '' ?>>
                                <span><?= e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </form>
        </div>
        <?= jeab_ordrar_fullmakt_status_html($open, $company, $openId, 'boka_bestall', false) ?>
        <div class="ordrar-next-row ordrar-spec-actions ordrar-boka-bestall-actions">
            <?php if (!$done): ?>
                <button class="ordrar-secondary" form="<?= e($formId) ?>" type="submit">Spara</button>
            <?php endif; ?>
            <button class="ordrar-primary" form="<?= e($formId) ?>" type="submit" name="intent" value="next" data-js="ordrar-nasta">Nästa</button>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}


function jeab_ordrar_fullmakt_status_html(array $open, string $company, int $openId, string $from, bool $idleHint): string
{
    $natbolag = trim((string) ($open['natbolag'] ?? ''));
    if ($natbolag === '') {
        $natbolag = jeab_ordrar_natbolag($open);
    }
    if ($natbolag !== 'Ellevio' || !function_exists('jeab_ellevio_fullmakt_state')) {
        return '';
    }
    $fullmakt = jeab_ellevio_fullmakt_state($open);
    $token = trim((string) ($fullmakt['token'] ?? ''));
    $errorText = trim((string) ($fullmakt['error'] ?? ''));
    $started = $token !== '' || $errorText !== '' || !empty($fullmakt['sent']) || !empty($fullmakt['signed']);
    if (!$idleHint && !$started) {
        return '';
    }
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => $from]);
    $retry = function_exists('jeab_ellevio_fullmakt_should_resend')
        ? jeab_ellevio_fullmakt_should_resend($open)
        : ($token !== '' && empty($fullmakt['sent']) && empty($fullmakt['signed']));
    ob_start();
    if (!empty($fullmakt['signed'])) {
        echo '<p class="ordrar-help">Fullmakten är signerad.</p>';
    } elseif (!empty($fullmakt['viewed'])) {
        echo '<p class="ordrar-help">Kunden har öppnat fullmakten. Vi väntar på signering innan föranmälan kan gå vidare.</p>';
    } elseif (!empty($fullmakt['handed_off'])) {
        echo '<p class="ordrar-help">Fullmakt är startad hos Bokmalen. Vi väntar på signering innan föranmälan kan gå vidare.</p>';
    } elseif (!empty($fullmakt['sent'])) {
        $via = function_exists('jeab_system_mail_path_label')
            ? jeab_system_mail_path_label(trim((string) ($open['fullmakt_mail_path'] ?? '')))
            : '';
        $sentLine = 'Fullmakt är skickad till kunden';
        $sentLine .= $via !== '' ? ' via ' . $via : '';
        echo '<p class="ordrar-help">' . e($sentLine) . '. Vi väntar på signering innan föranmälan kan gå vidare.</p>';
    } elseif ($errorText !== '') {
        echo '<p class="ordrar-help is-alert">' . e($errorText) . '</p>';
    } elseif ($idleHint) {
        echo '<p class="ordrar-help">Fullmakt till Ellevio startas hos Bokmalen när du trycker Nästa.</p>';
    } else {
        echo '<p class="ordrar-help">Fullmakt till Ellevio är inte skickad till kunden.</p>';
    }
    if ($retry && empty($fullmakt['viewed'])) {
        ?>
        <form data-js="ordrar-ajax" method="post" action="<?= e($action) ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="ordrar.fullmakt_resend">
            <input type="hidden" name="from" value="<?= e($from) ?>">
            <input type="hidden" name="id" value="<?= (int) $openId ?>">
            <button class="ordrar-secondary" type="submit">Skicka igen</button>
        </form>
        <?php
    }
    if (function_exists('jeab_ellevio_fullmakt_show_new') && jeab_ellevio_fullmakt_show_new($open)) {
        echo jeab_ellevio_fullmakt_new_form_html($action, $openId, $from, false);
    }
    return (string) ob_get_clean();
}

function jeab_ordrar_eon_html(array $open, string $company, int $openId, ?string $error = null): string
{
    $open = jeab_ordrar_display_row($open);
    $address = trim((string) ($open['huvudsokande_address'] ?? $open['address'] ?? ''));
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'eon']);
    $caseStatus = jeab_ordrar_foranmalan_case_status($open);
    $statuses = jeab_ordrar_foranmalan_case_statuses();
    $natbolag = trim((string) ($open['natbolag'] ?? ''));
    if ($natbolag === '') {
        $natbolag = jeab_ordrar_natbolag($open);
    }
    ob_start();
    ?>
    <div class="ordrar-card" data-js="ordrar-pane" data-stop="eon">
        <h2>Föranmälan</h2>
        <?php if ($address !== ''): ?>
            <p class="ordrar-help">Leveransadress: <?= e($address) ?></p>
        <?php endif; ?>
        <?php if ($natbolag !== ''): ?>
            <p class="ordrar-help">Nätbolag: <?= e($natbolag) ?></p>
        <?php endif; ?>
        <?= jeab_ordrar_fullmakt_status_html($open, $company, $openId, 'eon', true) ?>
        <?php if ($natbolag === 'SEOM' && function_exists('jeab_seom_fullmakt_panel_html')): ?>
            <?= jeab_seom_fullmakt_panel_html($open, $action) ?>
            <p class="ordrar-help">Nästa skapar SEOM-blanketten och mejlar den till Sam. Kunden får den inte automatiskt.</p>
        <?php elseif ($natbolag !== 'Ellevio'): ?>
            <?= jeab_ordrar_bokmalen_help_html($open, 'ordrar.foranmalan', $error) ?>
            <p class="ordrar-help">Nästa skickar föranmälan till Bokmalen.</p>
        <?php endif; ?>
        <p class="ordrar-help">Status loggas på ärendet och syns i översikten. Den går inte att ändra här.</p>
        <div class="ordrar-status-log" role="list" aria-label="Föranmälan-status">
            <?php foreach ($statuses as $value => $label): ?>
                <?php
                $on = $caseStatus === $value;
                $tone = $on ? jeab_ordrar_foranmalan_case_tone(['foranmalan_case_status' => $value]) : 'muted';
                ?>
                <span class="ordrar-status-pill is-<?= e($tone) ?><?= $on ? ' is-on' : '' ?>" role="listitem"><?= e($label) ?></span>
            <?php endforeach; ?>
        </div>
        <?php
        $foranmald = trim((string) ((is_array($open['steps'] ?? null) ? $open['steps'] : [])['foranmald'] ?? ''));
        $alreadySent = $foranmald !== '';
        ?>
        <?php if ($alreadySent): ?>
            <?php
            $resentAt = trim((string) ($open['foranmalan_resent_at'] ?? ''));
            $canResend = jeab_ordrar_foranmalan_can_resend($open);
            ?>
            <p class="ordrar-help">Föranmälan skickad<?= $foranmald !== '' ? ' ' . e(jeab_ordrar_mmdd($foranmald)) : '' ?>. Bokmalen har fått ärendet.<?= $resentAt !== '' ? ' Skickad igen ' . e(jeab_ordrar_mmdd($resentAt)) . ' ' . e(substr($resentAt, 11, 5)) . '.' : '' ?></p>
            <?php if ($canResend): ?>
            <form data-js="ordrar-ajax" method="post" action="<?= e($action) ?>">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="ordrar.foranmalan_resend">
                <input type="hidden" name="from" value="eon">
                <input type="hidden" name="id" value="<?= (int) $openId ?>">
                <button class="ordrar-secondary" type="submit" data-js="ordrar-resend">Skicka igen</button>
            </form>
            <?php endif; ?>
        <?php else: ?>
            <?php if (jeab_ordrar_foranmalan_send_failed($open)): ?>
                <p class="ordrar-help is-alert" role="alert"><?= e(jeab_ordrar_foranmalan_send_fail_message()) ?></p>
                <p class="ordrar-help">Status: <?= e(jeab_ordrar_foranmalan_unsent_status()) ?>.</p>
            <?php endif; ?>
            <form data-js="ordrar-ajax" method="post" action="<?= e($action) ?>">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="ordrar.advance">
                <input type="hidden" name="from" value="eon">
                <input type="hidden" name="id" value="<?= (int) $openId ?>">
                <button class="ordrar-primary" type="submit" data-js="ordrar-nasta"><?= jeab_ordrar_foranmalan_send_failed($open) ? 'Skicka igen' : 'Nästa' ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

function jeab_ordrar_solo_html(int $openId, string $view, string $company): string
{
    if ($view === 'fardiganmal' || $view === 'fakturera') {
        $open = jeab_ordrar_require($openId);
        return jeab_ordrar_fakturera_html($open, $company, $openId);
    }
    $titles = [
        'paminelse' => 'Påminnelse',
        'kop' => 'Köp',
    ];
    ob_start();
    ?>
    <div class="ordrar-card" data-js="ordrar-pane" data-stop="<?= e($view) ?>">
        <h2><?= e($titles[$view] ?? '') ?></h2>
    </div>
    <?php
    return (string) ob_get_clean();
}

function jeab_ordrar_fakturera_html(array $open, string $company, int $openId): string
{
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'fardiganmal']);
    $current = jeab_ordrar_fakturera_status($open);
    $statuses = jeab_ordrar_fakturera_statuses();
    $orderNo = trim((string) ($open['fortnox_order'] ?? ''));
    ob_start();
    ?>
    <div class="ordrar-card" data-js="ordrar-pane" data-stop="fardiganmal">
        <h2>Fakturera</h2>
        <?php if ($current === 'faktura_skapad'): ?>
            <p class="ordrar-help">Faktura skapad.</p>
        <?php elseif ($current === 'order_skapad' || $orderNo !== ''): ?>
            <p class="ordrar-help">Order skapad.</p>
        <?php endif; ?>
        <?php if ($orderNo !== ''): ?>
            <p class="ordrar-help">Fortnox-order: <?= e($orderNo) ?></p>
        <?php endif; ?>
        <div class="ordrar-status-log" role="list" aria-label="Fakturera-status">
            <?php foreach ($statuses as $value => $label): ?>
                <?php
                $on = $current === $value;
                $tone = match (true) {
                    $on && $value === 'faktura_skapad' => 'ok',
                    $on && $value === 'order_skapad' => 'warn',
                    default => 'muted',
                };
                ?>
                <span class="ordrar-status-pill is-<?= e($tone) ?><?= $on ? ' is-on' : '' ?>" role="listitem"><?= e($label) ?></span>
            <?php endforeach; ?>
        </div>
        <form data-js="ordrar-ajax" method="post" action="<?= e($action) ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="ordrar.advance">
            <input type="hidden" name="from" value="fardiganmal">
            <input type="hidden" name="id" value="<?= (int) $openId ?>">
            <button class="ordrar-primary" type="submit" data-js="ordrar-nasta">Nästa</button>
        </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

function jeab_ordrar_vad_html(array $open, string $company, int $openId): string
{
    $open = jeab_ordrar_display_row($open);
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'vad']);
    $address = trim((string) ($open['huvudsokande_address'] ?? $open['address'] ?? ''));
    ob_start();
    ?>
    <div class="ordrar-card" data-js="ordrar-pane" data-stop="vad">
        <form data-js="ordrar-ajax" method="post" action="<?= e($action) ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="ordrar.vad">
            <input type="hidden" name="id" value="<?= (int) $openId ?>">
            <input type="hidden" name="intent" value="" data-js="intent-mirror">
            <h2>Vad/pris/anteckningar</h2>
            <?php if ($address !== ''): ?>
                <p class="ordrar-help">Leveransadress: <?= e($address) ?></p>
            <?php endif; ?>
            <label class="ordrar-field">
                <span>Vad som ska installeras</span>
                <input type="text" name="install_what" value="<?= e((string) ($open['install_what'] ?? '')) ?>" autocomplete="off">
                <p class="ordrar-field-help">Detta blir specifikationen på fakturan</p>
            </label>
            <label class="ordrar-field">
                <span>Pris</span>
                <input type="text" name="pris" inputmode="numeric" value="<?= !empty($open['pris']) ? e(jeab_ordrar_kr((int) $open['pris'])) : '' ?>" autocomplete="off">
                <p class="ordrar-field-help">Pris efter grön teknik – det kunden betalar.</p>
            </label>
            <?= jeab_ordrar_payouts_html($open) ?>
            <label class="ordrar-field">
                <span>Anteckning till installation</span>
                <textarea name="install_note" rows="3"><?= e((string) ($open['install_note'] ?? '')) ?></textarea>
                <p class="ordrar-field-help">Syns inte för kunden. kommer med i bokningen i kalendern för installatören</p>
            </label>
            <div class="ordrar-next-row">
                <button class="ordrar-secondary" type="submit">Spara</button>
                <button class="ordrar-primary" type="submit" name="intent" value="next" data-js="ordrar-nasta">Nästa</button>
            </div>
        </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

function jeab_ordrar_bestall_step_html(array $open, string $company, int $openId, mixed $store = null, ?string $error = null): string
{
    $store = $store instanceof JeabStore ? $store : jeab_ordrar_try_store();
    $alreadyBestalld = jeab_ordrar_bestalld($open);
    $proposal = $store instanceof JeabStore ? jeab_ordrar_install_proposal($store, $open) : null;
    $status = jeab_ordrar_bestallning_status($open);
    if ($status === '') {
        $status = 'A';
    }
    $choices = jeab_ordrar_bestallning_choices();
    $shopLabel = jeab_ordrar_shop_label($open);
    $bestalldAt = trim((string) ($open['steps']['bestalld'] ?? ''));
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'bestall']);
    $bokmalenHelp = jeab_ordrar_bokmalen_help_html($open, 'ordrar.bestall', $error);
    ob_start();
    ?>
    <div class="ordrar-card" data-js="ordrar-pane" data-stop="bestall">
        <form data-js="ordrar-ajax" method="post" action="<?= e($action) ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="ordrar.bestall_steg">
            <input type="hidden" name="id" value="<?= (int) $openId ?>">
            <input type="hidden" name="intent" value="" data-js="intent-mirror">
            <h2>Beställ</h2>
            <p class="ordrar-help">Önskad leverans följer installationstiden och går inte att ändra. Tiden bokas när du går vidare.</p>
            <?= jeab_ordrar_proposal_block_html($proposal) ?>
            <?php if ($bestalldAt !== ''): ?>
                <p class="ordrar-help">Beställd <?= e(jeab_ordrar_mmdd($bestalldAt)) ?>.</p>
            <?php endif; ?>
            <?php if ($shopLabel !== ''): ?>
                <p class="ordrar-help ordrar-shop"><?= e($shopLabel) ?></p>
            <?php endif; ?>
            <?= $bokmalenHelp ?>
            <fieldset class="ordrar-foranmalan ordrar-bestallning">
                <legend>Beställning</legend>
                <div class="chips ordrar-foranmalan-choices" role="radiogroup" aria-label="Beställning">
                    <?php foreach ($choices as $value => $label): ?>
                        <label class="chip-opt">
                            <input type="radio" name="bestallning_status" value="<?= e($value) ?>"<?= $status === $value ? ' checked' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="ordrar-next-row">
                <?php if (!$alreadyBestalld): ?>
                    <button class="ordrar-secondary" type="submit">Spara</button>
                <?php endif; ?>
                <button class="ordrar-primary" type="submit" name="intent" value="next" data-js="ordrar-nasta">Nästa</button>
            </div>
        </form>
        <?= jeab_ordrar_fullmakt_status_html($open, $company, $openId, 'bestall', false) ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Kundfält som fakturering visar. Kontakten när flaggan är på och länken finns.
 * Äldre rader utan länk, och flaggan av, läser det som ligger på ordern.
 *
 * @param array<string, mixed> $open
 * @return array{name:string,email:string,phone:string,address:string}
 */
function jeab_ordrar_fakturering_customer(array $open): array
{
    if (function_exists('jeab_ordrar_display_row')) {
        $open = jeab_ordrar_display_row($open);
    }

    return [
        'name' => trim((string) ($open['huvudsokande'] ?? $open['name'] ?? $open['customer_name'] ?? '')),
        'email' => trim((string) ($open['email'] ?? '')),
        'phone' => trim((string) ($open['phone'] ?? '')),
        'address' => trim((string) ($open['address'] ?? $open['huvudsokande_address'] ?? '')),
    ];
}

function jeab_ordrar_fakturering_html(array $open, string $company, int $openId, ?string $error = null): string
{
    $open = jeab_ordrar_display_row($open);
    $customer = jeab_ordrar_fakturering_customer($open);
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'fakturering']);
    $orderNo = trim((string) ($open['fortnox_order'] ?? ''));
    $invoiceNo = jeab_ordrar_invoice_number($open);
    $lines = [
        ['row_id' => '', 'article' => '', 'description' => '', 'housework' => '', 'qty' => '', 'price' => ''],
        ['row_id' => '', 'article' => '', 'description' => '', 'housework' => '', 'qty' => '', 'price' => ''],
        ['row_id' => '', 'article' => '', 'description' => '', 'housework' => '', 'qty' => '', 'price' => ''],
        ['row_id' => '', 'article' => '', 'description' => '', 'housework' => '', 'qty' => '', 'price' => ''],
    ];
    $attBetala = '';
    $loadError = '';
    $fxSource = '';
    $storedRows = is_array($open['fortnox_order_rows'] ?? null) ? $open['fortnox_order_rows'] : [];
    $pullFortnox = $orderNo !== '' && jeab_ordrar_external_live();
    if ($pullFortnox) {
        // Uppdatera (?refresh=1) slår på externa läsningar. Raderna hämtas från Fortnox, inte från offertformeln.
        try {
            $snapshot = jeab_ordrar_fortnox_fetch_order($open);
            $lines = jeab_ordrar_fortnox_editor_lines(jeab_ordrar_fortnox_order_rows($snapshot));
            $attBetala = jeab_ordrar_fortnox_att_betala($snapshot);
            $ref = jeab_ordrar_fortnox_invoice_reference($snapshot);
            if ($ref !== '') {
                $invoiceNo = $ref;
            }
            $fxSource = 'fortnox';
            jeab_ordrar_fortnox_store_rows($open, jeab_ordrar_fortnox_order_rows($snapshot), $attBetala);
        } catch (Throwable $e) {
            $reason = trim($e->getMessage());
            $loadError = $reason !== '' ? $reason : 'Fortnox-ordern kunde inte hämtas.';
        }
    }
    if ($fxSource === '' && $orderNo !== '' && $storedRows !== []) {
        $lines = jeab_ordrar_fortnox_editor_lines($storedRows);
        $attBetala = trim((string) ($open['fortnox_att_betala'] ?? ''));
        $fxSource = 'snapshot';
    } elseif ($fxSource === '' && $orderNo !== '' && !$pullFortnox) {
        $attBetala = trim((string) ($open['fortnox_att_betala'] ?? ''));
    }
    if ($fxSource === '' && $orderNo === '' && function_exists('jeab_ordrar_v3_fakturering_from_kalkyl')) {
        $prefilled = jeab_ordrar_v3_fakturering_from_kalkyl($open, [
            'lines' => $lines,
            'pay' => '',
            'error' => '',
            'source' => '',
            'invoice' => $invoiceNo,
        ]);
        if (($prefilled['source'] ?? '') === 'kalkyl' && is_array($prefilled['lines'] ?? null)) {
            $lines = $prefilled['lines'];
            $pay = trim((string) ($prefilled['pay'] ?? ''));
            if ($pay !== '') {
                $attBetala = $pay;
            }
            $fxSource = 'kalkyl';
        }
    }
    $choices = jeab_ordrar_fortnox_housework_choices();
    $fxUrl = jeab_ordrar_fortnox_order_url($open);
    ob_start();
    ?>
    <div class="ordrar-card ordrar-fakturering" data-js="ordrar-pane" data-stop="fakturering" data-fx-source="<?= e($fxSource) ?>" data-sole-name="<?= e($customer['name']) ?>" data-sole-email="<?= e($customer['email']) ?>" data-sole-phone="<?= e($customer['phone']) ?>" data-sole-address="<?= e($customer['address']) ?>">
        <?php if ($loadError !== '' && !(is_string($error) && trim($error) !== '')): ?>
            <p class="ordrar-help is-alert" role="alert" data-js="ordrar-ajax-error"><?= e(jeab_ordrar_save_fail_text($loadError)) ?></p>
        <?php endif; ?>
        <?php $gtError = trim((string) ($open['fortnox_gt_error'] ?? '')); ?>
        <?php if ($gtError !== ''): ?>
            <p class="ordrar-help is-alert" role="alert" data-js="ordrar-ajax-error"><?= e(jeab_ordrar_save_fail_text($gtError)) ?></p>
        <?php endif; ?>
        <form data-js="ordrar-ajax" method="post" action="<?= e($action) ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="ordrar.fakturering">
            <input type="hidden" name="id" value="<?= (int) $openId ?>">
            <input type="hidden" name="intent" value="save" data-js="intent-mirror">
            <h2>Fakturering</h2>
            <?php if ($orderNo !== ''): ?>
                <p class="ordrar-help">Fortnox-order:
                    <?php if ($fxUrl !== ''): ?>
                        <a class="ordrar-result-link" href="<?= e($fxUrl) ?>" target="_blank" rel="noopener"><?= e($orderNo) ?></a>
                    <?php else: ?>
                        <?= e($orderNo) ?>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <?php $fxErr = trim((string) ($open['fortnox_error'] ?? '')); ?>
                <?php if ($fxErr !== ''): ?>
                    <p class="ordrar-help is-alert" role="alert"><?= e(jeab_ordrar_save_fail_text($fxErr)) ?></p>
                    <?php if (function_exists('jeab_ordrar_fortnox_pnr_gap') && jeab_ordrar_fortnox_pnr_gap($fxErr)): ?>
                        <?= jeab_ordrar_fortnox_pnr_next_html($open, (int) $openId) ?>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="ordrar-help">Ingen Fortnox-order ännu. Spara skapar ordern och skriver raderna.</p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($invoiceNo !== ''): ?>
                <p class="ordrar-help">Faktura: <?= e($invoiceNo) ?></p>
            <?php endif; ?>
            <div class="ordrar-fx-lines">
                <div class="ordrar-fx-head" aria-hidden="true">
                    <span>Artikelnr</span>
                    <span>Benämning</span>
                    <span>Husarbete</span>
                    <span>Antal</span>
                    <span>À-pris</span>
                    <span>Summa</span>
                </div>
                <?php foreach ($lines as $line): ?>
                    <div class="ordrar-fx-line">
                        <input type="hidden" name="fx_rowid[]" value="<?= e((string) $line['row_id']) ?>">
                        <label class="ordrar-field">
                            <span class="ordrar-fx-lbl">Artikelnr</span>
                            <input type="text" name="fx_article[]" value="<?= e((string) $line['article']) ?>" autocomplete="off">
                        </label>
                        <label class="ordrar-field">
                            <span class="ordrar-fx-lbl">Benämning</span>
                            <input type="text" name="fx_desc[]" value="<?= e((string) $line['description']) ?>" autocomplete="off">
                        </label>
                        <label class="ordrar-field">
                            <span class="ordrar-fx-lbl">Husarbete</span>
                            <select name="fx_hw[]">
                                <?php foreach ($choices as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= (string) $line['housework'] === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="ordrar-field">
                            <span class="ordrar-fx-lbl">Antal</span>
                            <input type="text" name="fx_qty[]" inputmode="decimal" value="<?= e((string) ($line['qty'] ?? '')) ?>" autocomplete="off">
                        </label>
                        <label class="ordrar-field">
                            <span class="ordrar-fx-lbl">À-pris</span>
                            <input type="text" name="fx_price[]" inputmode="decimal" value="<?= e((string) $line['price']) ?>" autocomplete="off">
                        </label>
                        <p class="ordrar-fx-sum"><span class="ordrar-fx-lbl">Summa</span><strong data-js="fx-sum"><?= e(jeab_ordrar_fortnox_line_sum_text($line)) ?></strong></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="ordrar-fx-pay"><span>Att betala</span><strong><?= e($attBetala !== '' ? $attBetala : '–') ?></strong></p>
            <?= jeab_ordrar_payouts_html($open) ?>
            <script>
            (function () {
                var root = document.querySelector('[data-stop="fakturering"]');
                if (!root) { return; }
                function num(value) {
                    var s = String(value || '').replace(/[\s\u00a0\u202f]/g, '').replace(',', '.');
                    var n = parseFloat(s);
                    return isFinite(n) ? n : 0;
                }
                function money(n) {
                    var rounded = Math.round(n);
                    var text = Math.abs(n - rounded) < 0.001 ? String(rounded) : n.toFixed(2).replace('.', ',');
                    text = text.replace(/\B(?=(\d{3})+(?!\d))/g, '\u202f');
                    return text + ' kr';
                }
                function paint(line) {
                    var price = line.querySelector('[name="fx_price[]"]');
                    var qty = line.querySelector('[name="fx_qty[]"]');
                    var sum = line.querySelector('[data-js="fx-sum"]');
                    if (!price || !sum) { return; }
                    if (String(price.value || '').trim() === '') {
                        sum.textContent = '';
                        return;
                    }
                    var q = qty ? num(qty.value) : 0;
                    if (q <= 0) { q = 1; }
                    sum.textContent = money(num(price.value) * q);
                }
                Array.prototype.forEach.call(root.querySelectorAll('.ordrar-fx-line'), function (line) {
                    line.addEventListener('input', function () { paint(line); });
                });
            })();
            </script>
            <input type="hidden" name="faktura_bekrafta" value="1" data-faktura-flag disabled>
            <div class="ordrar-next-row">
                <button class="ordrar-secondary" type="submit" name="intent" value="save">Spara</button>
                <button class="ordrar-primary" type="button" data-faktura-ask="1">Skapa faktura</button>
            </div>
            <div class="ordrar-faktura-confirm" data-faktura-confirm hidden>
                <h3>Skapa faktura?</h3>
                <p>Kontrollera belopp och rader. Fortnox skapar fakturan först när du bekräftar. Inget skickas till kunden.</p>
                <label class="ordrar-field"><input type="checkbox" name="faktura_bekrafta" value="1" data-faktura-check> Belopp och rader är bekräftade</label>
                <p class="ordrar-fx-pay"><span>Att betala</span><strong><?= e($attBetala !== '' ? $attBetala : '–') ?></strong></p>
                <div class="ordrar-next-row">
                    <button class="ordrar-secondary" type="button" data-faktura-back>Tillbaka</button>
                    <button class="ordrar-primary" type="submit" name="intent" value="faktura" data-faktura-go="1">Skapa faktura</button>
                </div>
            </div>
        </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

function jeab_ordrar_save_vad(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    if (!jeab_ordrar_is_solbatteri($row)) {
        throw new InvalidArgumentException('Vad/pris/anteckningar gäller sol och batteri.');
    }
    $row['install_what'] = trim((string) ($input['install_what'] ?? $row['install_what'] ?? ''));
    $prevPris = (int) ($row['pris'] ?? 0);
    $row['pris'] = (int) round(jeab_purchase_amount($input['pris'] ?? $row['pris'] ?? 0));
    $row['install_note'] = trim((string) ($input['install_note'] ?? $row['install_note'] ?? ''));
    $existingKundpris = (int) ($row['kundpris'] ?? 0);
    $prisEdited = $row['pris'] > 0 && $row['pris'] !== $prevPris;
    if ($existingKundpris <= 0 || $prisEdited) {
        $row['kundpris'] = jeab_ordrar_resolve_kundpris($row['pris'], $existingKundpris);
    }
    if (function_exists('jeab_ordrar_payouts_posted') && jeab_ordrar_payouts_posted($input)) {
        $row = jeab_ordrar_payouts_merge($row, $input);
    }
    $row = jeab_ordrar_stamp_today($row, 'vad');
    $row['updated_at'] = jeab_now();
    $row = jeab_purchase_put($row);
    jeab_ordrar_refresh_linked_booking_notes($row);
    return $row;
}

function jeab_ordrar_save_bestall_steg(JeabStore $store, array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $row = jeab_ordrar_apply_bestallning_status($row, $input);
    if (jeab_ordrar_bestallning_status($row) === '') {
        $row['bestallning_status'] = 'A';
    }
    $proposal = jeab_ordrar_install_proposal($store, $row);
    $install = jeab_ordrar_ymd((string) ($proposal['starts_at'] ?? ''));
    if ($install === '') {
        $install = jeab_ordrar_suggest_install_ymd($row, $store);
    }
    $delivery = (string) ($proposal['delivery'] ?? '');
    if ($delivery === '') {
        $delivery = jeab_ordrar_suggest_delivery_ymd($row, $store, $install);
    }
    if ($delivery !== '') {
        $row['onskad_leverans'] = $delivery;
        $row['önskad_leverans'] = $delivery;
        $row['leverans'] = $delivery;
        $row['faktisk_leverans'] = $delivery;
    }
    $status = jeab_ordrar_bestallning_status($row);
    $advance = jeab_ordrar_wants_advance($input);
    $bookInput = [];
    if ($advance && $status !== 'B') {
        $bookInput = jeab_ordrar_proposal_book_input($store, $row, $input);
    }
    $row['updated_at'] = jeab_now();
    $row = jeab_purchase_put($row);
    if (!$advance) {
        return $row;
    }
    if ($status === 'B') {
        $input['utkast'] = '1';
        return jeab_ordrar_save_bestall($input + [
            'onskad_leverans' => $delivery,
            'leverans' => $delivery,
            'bestallning_status' => $status,
        ]);
    }
    if ($status === 'C') {
        if (!jeab_ordrar_is_booked($row, $store) && ($bookInput['book_confirmed'] ?? '') === '1') {
            $row = jeab_ordrar_create_booking($store, $bookInput + [
                'id' => (int) ($row['reminder_id'] ?? $input['id'] ?? 0),
            ]);
        }
        if (!jeab_ordrar_bestalld($row)) {
            $row = jeab_ordrar_stamp_bestalld_at($row);
            $row['updated_at'] = jeab_now();
            $row = jeab_purchase_put($row);
        }
        if (function_exists('jeab_ordrar_solkoll_after_placed')) {
            $row = jeab_ordrar_solkoll_after_placed($row);
        }
        return $row;
    }
    $placeInput = $input;
    unset($placeInput['utkast']);
    $placeInput['intent'] = 'bestall';
    $placeInput['leverans'] = $delivery;
    $placeInput['onskad_leverans'] = $delivery;
    return jeab_ordrar_save_bestall($placeInput);
}

function jeab_ordrar_save_pick(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $kind = jeab_purchase_normalize_kind($input['kind'] ?? '');
    if ($kind !== null) {
        $row['kind'] = $kind;
    }
    $row['huvudsokande_manual'] = true;
    $row = jeab_ordrar_apply_confirm_fields($row, $input);
    $row['huvudsokande_manual'] = true;
    $picked = trim((string) ($row['huvudsokande'] ?? ''));
    jeab_ordrar_remember_huvud(trim((string) ($row['contact_id'] ?? '')), $picked);
    $row['updated_at'] = jeab_now();
    $row = jeab_purchase_put($row);
    jeab_ordrar_sync_lm_contact($row);

    return $row;
}

function jeab_ordrar_apply_confirm_fields(array $row, array $input): array
{
    $before = $row;
    $huvud = trim((string) ($input['huvudsokande'] ?? ''));
    $pnr = jeab_idnr_clean(trim((string) ($input['huvudsokande_personnummer'] ?? $input['personnummer'] ?? '')));
    $addr = trim((string) ($input['huvudsokande_address'] ?? ''));
    $fast = trim((string) ($input['fastighetsbeteckning'] ?? ''));
    $andel = trim((string) ($input['huvudsokande_andel'] ?? ''));
    $lagfart = trim((string) ($input['huvudsokande_lagfart'] ?? ''));
    if ($huvud !== '') {
        $row = jeab_ordrar_apply_primary_name($row, $huvud);
        $row['huvudsokande_manual'] = true;
    }
    if ($pnr !== '') {
        $row['personnummer'] = $pnr;
    }
    if ($addr !== '') {
        $row['huvudsokande_address'] = $addr;
    }
    if ($fast !== '') {
        $row['fastighetsbeteckning'] = $fast;
    }
    if ($andel !== '') {
        $row['huvudsokande_andel'] = $andel;
    }
    if ($lagfart !== '') {
        $row['huvudsokande_lagfart'] = $lagfart;
    }
    $postedMed = array_key_exists('medsokande_name', $input) || array_key_exists('medsokande_personnummer', $input);
    $postedOwners = array_key_exists('owner_name', $input) || array_key_exists('owner_pnr', $input);
    $med = $postedMed
        ? jeab_ordrar_people_from_input($input, 'medsokande_name', 'medsokande_personnummer', 3)
        : [];
    $owners = $postedOwners
        ? jeab_ordrar_people_from_named_input($input, 'owner_name', 'owner_pnr', 'owner_address', 4)
        : [];
    if ($postedOwners) {
        $owners = jeab_ordrar_dedupe_people($owners, 8);
        $row['owners'] = $owners;
        $row = jeab_ordrar_mirror_huvud_pnr($row);
        $fromOwner = jeab_ordrar_format_pnr((string) ($row['personnummer'] ?? ''));
        if ($fromOwner !== '') {
            $pnr = $fromOwner;
        }
    }
    $savedHuvud = trim((string) ($row['huvudsokande'] ?? $huvud));
    if ($postedMed) {
        $med = jeab_ordrar_other_owners(jeab_ordrar_dedupe_people($med, 8), $savedHuvud);
        $row['medsokande'] = $med !== [] ? $med : jeab_ordrar_other_owners($owners, $savedHuvud);
    } elseif ($postedOwners) {
        $row['medsokande'] = jeab_ordrar_other_owners($owners, $savedHuvud);
    } elseif ($med !== []) {
        $row['medsokande'] = $med;
    }
    // Ägarkort som kom från uppslaget och sparas oförändrade är automatiska; andra handskrivna.
    if ($postedOwners && is_array($input['owner_forslag'] ?? null)) {
        $forslag = array_map('strval', $input['owner_forslag']);
        $prevMark = is_array($before['identity_source']['owners'] ?? null) ? $before['identity_source']['owners'] : [];
        $prev = is_array($prevMark['entries'] ?? null) ? $prevMark['entries'] : [];
        $sigs = [];
        foreach (array_merge(jeab_ordrar_people_list($row['owners'] ?? [], 8), jeab_ordrar_people_list($row['medsokande'] ?? [], 8)) as $person) {
            $sig = jeab_ordrar_owner_sig($person);
            if ((in_array($sig, $forslag, true) || in_array($sig, $prev, true)) && !in_array($sig, $sigs, true)) {
                $sigs[] = $sig;
            }
        }
        $marks = is_array($row['identity_source'] ?? null) ? $row['identity_source'] : [];
        $marks['owners'] = ['src' => 'lookup', 'entries' => $sigs, 'address' => (string) ($prevMark['address'] ?? ''), 'at' => jeab_now()];
        $row['identity_source'] = $marks;
    }
    // Förslag från uppslaget som sparas oförändrat är automatiska; ändrat för hand blir manuellt.
    $key = jeab_ordrar_address_key($before);
    if ($pnr !== '') {
        $row = jeab_ordrar_mark_posted_identity($before, $row, 'personnummer', (string) ($input['personnummer_forslag'] ?? ''), $key);
    }
    if ($fast !== '') {
        $row = jeab_ordrar_mark_posted_identity($before, $row, 'fastighetsbeteckning', (string) ($input['fastighetsbeteckning_forslag'] ?? ''), $key);
    }
    // Objektadressen ändras bara i Objekt (jeab_ordrar_save_objekt), där nytt uppslag görs.
    $row['lm_lookup_error'] = null;
    return jeab_ordrar_backfill_lookup($row, null, true);
}

function jeab_ordrar_save_laddbox(array $input): array
{
    $row = jeab_ordrar_apply_confirm_fields(jeab_ordrar_require((int) ($input['id'] ?? 0)), $input);
    $row['install_what'] = trim((string) ($input['install_what'] ?? ''));
    $row['pris'] = (int) round(jeab_purchase_amount($input['pris'] ?? 0));
    $row['install_note'] = trim((string) ($input['install_note'] ?? ''));
    $row['stop3_saved'] = true;
    $row['updated_at'] = jeab_now();
    $row = jeab_purchase_put($row);
    jeab_ordrar_sync_lm_contact($row);
    jeab_ordrar_refresh_linked_booking_notes($row);
    return $row;
}

function jeab_ordrar_resolve_kundpris(mixed $posted, int $existing): int
{
    $amount = (int) round(jeab_purchase_amount($posted));
    if ($amount > 0) {
        return $amount;
    }
    return $existing > 0 ? $existing : 0;
}

/** Mellanslag och annat som inte är siffror skalas av. `735 999 …` blir `735999…`. */
function jeab_ordrar_anlaggnings_id_digits(string $raw): string
{
    return preg_replace('/\D+/u', '', $raw) ?? '';
}

/**
 * kWh som decimaltal > 0. Komma eller punkt. Annars null.
 */
function jeab_ordrar_kwh_amount(string $raw): ?float
{
    $s = trim(str_replace(["\u{00a0}", ' '], '', $raw));
    if ($s === '') {
        return null;
    }
    $s = str_replace(',', '.', $s);
    if (preg_match('/^\d+(?:\.\d+)?$/', $s) !== 1) {
        return null;
    }
    $n = (float) $s;
    return $n > 0 ? $n : null;
}

function jeab_ordrar_save_batterisol(array $input): array
{
    $row = jeab_ordrar_apply_confirm_fields(jeab_ordrar_require((int) ($input['id'] ?? 0)), $input);
    if (array_key_exists('vaxelriktare', $input)) {
        $row['vaxelriktare'] = trim((string) $input['vaxelriktare']);
    }
    $vaxel = trim((string) ($row['vaxelriktare'] ?? ''));
    $kwh = trim((string) ($input['kwh'] ?? ''));
    if ($kwh !== '' && jeab_ordrar_kwh_amount($kwh) === null) {
        throw new InvalidArgumentException('kWh ska vara ett tal större än 0.');
    }
    $row['kwh'] = $kwh;
    $row['kundpris'] = jeab_ordrar_resolve_kundpris($input['kundpris'] ?? null, (int) ($row['kundpris'] ?? 0));
    $row['anlaggnings_id'] = jeab_ordrar_anlaggnings_id_digits((string) ($input['anlaggnings_id'] ?? $row['anlaggnings_id'] ?? ''));
    if (array_key_exists('natbolag', $input)) {
        $nat = trim((string) $input['natbolag']);
        if (in_array($nat, jeab_ordrar_natbolag_options(), true)) {
            $row['natbolag'] = $nat;
        }
    }
    $row = jeab_ordrar_apply_foranmalan_status($row, $input);
    if (jeab_ordrar_foranmalan_status($row) === '') {
        $row['foranmalan_status'] = 'A';
    }
    $row['stop3_saved'] = true;
    // Nästa + Automatiskt är samma skickning som eon-kortets Nästa.
    // Spec stämplas först när Bokmalen tagit emot, så steget inte ser klart ut vid fel.
    $send = jeab_ordrar_wants_advance($input)
        && jeab_ordrar_is_solbatteri($row)
        && jeab_ordrar_foranmalan_status($row) === 'A';
    if ($send) {
        if ($vaxel === '') {
            throw new InvalidArgumentException('Välj växelriktare.');
        }
        if (jeab_ordrar_kwh_amount((string) $row['kwh']) === null) {
            throw new InvalidArgumentException('kWh ska vara ett tal större än 0.');
        }
    }
    if (!$send) {
        $row = jeab_ordrar_stamp_today($row, 'spec');
    }
    $row['updated_at'] = jeab_now();
    $row = jeab_purchase_put($row);
    jeab_ordrar_sync_lm_contact($row);
    jeab_ordrar_refresh_linked_booking_notes($row);
    if (!$send) {
        return function_exists('jeab_ordrar_fortnox_heal') ? jeab_ordrar_fortnox_heal($row) : $row;
    }
    $row = jeab_ordrar_foranmalan_nasta(jeab_ordrar_try_store(), $row);
    $row = jeab_ordrar_stamp_today($row, 'spec');
    $row['updated_at'] = jeab_now();
    $row = jeab_purchase_put($row);
    jeab_ordrar_refresh_linked_booking_notes($row);
    return function_exists('jeab_ordrar_fortnox_heal') ? jeab_ordrar_fortnox_heal($row) : $row;
}

function jeab_ordrar_fortnox_on_next(array $row): array
{
    if (jeab_ordrar_fortnox_attach_locked($row)) {
        return $row;
    }
    try {
        if (isset($GLOBALS['_JEAB_FORTNOX_CREATE']) && is_callable($GLOBALS['_JEAB_FORTNOX_CREATE'])) {
            $created = ($GLOBALS['_JEAB_FORTNOX_CREATE'])($row);
            return is_array($created) ? $created : $row;
        }
        return jeab_ordrar_create_fortnox(['id' => (int) ($row['reminder_id'] ?? 0)]);
    } catch (Throwable $e) {
        $rawMsg = $e->getMessage() !== '' ? $e->getMessage() : 'Fortnox-ordern skapades inte.';
        $masked = jeab_ordrar_scrub_pnr($rawMsg);
        $msg = is_string($masked) && $masked !== '' ? $masked : 'Fortnox-ordern skapades inte.';
        // Bokningen är redan gjord. Spara alltid felet på ordern (även «Saknas»)
        // så Fakturering visar varför och nästa sparning försöker igen.
        $row['fortnox_error'] = $msg;
        $row['updated_at'] = jeab_now();
        try {
            $row = jeab_purchase_put($row);
        } catch (Throwable) {
        }
        if (str_contains($msg, 'Saknas för Fortnox-order')) {
            throw new InvalidArgumentException($msg);
        }
        $row['updated_at'] = jeab_now();
        try {
            $row = jeab_purchase_put($row);
        } catch (Throwable) {
            // Feltexten finns kvar i svaret även om lagringen misslyckas.
        }
        return $row;
    }
}

/**
 * Självläkning: en vunnen order (bokad eller bekräftad) utan Fortnox-order
 * skrivs till Fortnox när den sparas igen. Aldrig faktura. Fel sparas på ordern.
 */
function jeab_ordrar_fortnox_heal(array $row): array
{
    if (trim((string) ($row['fortnox_order'] ?? '')) !== '' || jeab_ordrar_fortnox_attach_locked($row)) {
        return $row;
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    $won = (int) ($row['booking_id'] ?? 0) > 0 || trim((string) ($steps['bekraftad'] ?? '')) !== '';
    if (!$won || !empty($row['closed']) || (function_exists('jeab_ordrar_allt_klart') && jeab_ordrar_allt_klart($row)) || jeab_purchase_normalize_kind($row['kind'] ?? '') === null) {
        return $row;
    }
    if (function_exists('jeab_ordrar_fortnox_missing') && jeab_ordrar_fortnox_missing($row) !== []) {
        return $row;
    }
    try {
        return jeab_ordrar_fortnox_on_next($row);
    } catch (Throwable) {
        return $row;
    }
}

function jeab_ordrar_is_solbatteri(array $row): bool
{
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    return $kind === 'batteri' || $kind === 'sol';
}

/**
 * Kort flöde Bekräfta → Fakturering. Bara elbilsladdare.
 * Batteri och sol använder samma långa steglista. Solkoll hör bara till sol.
 */
function jeab_ordrar_bekrafta_slutfor(array $row): bool
{
    return jeab_ordrar_ui_kind($row['kind'] ?? '') === 'laddbox';
}

function jeab_ordrar_ui_kind(mixed $raw): string
{
    $n = jeab_purchase_normalize_kind($raw) ?? '';
    if ($n !== '') {
        return $n;
    }
    $k = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $raw), 'UTF-8') : strtolower(trim((string) $raw));
    if ($k === '') {
        return '';
    }
    if (str_contains($k, 'sol')) {
        return 'sol';
    }
    if (str_contains($k, 'batter')) {
        return 'batteri';
    }
    if (str_contains($k, 'ladd')) {
        return 'laddbox';
    }
    return '';
}

/** Valbara nätägare. SEOM är Sollentuna Elnät AB. */
function jeab_ordrar_natbolag_options(): array
{
    return ['Ellevio', 'E.ON', 'Vattenfall', 'SEOM', 'Kraftringen', 'Öresundskraft'];
}

function jeab_ordrar_natbolag_from_foretag(string $name): string
{
    $n = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $n = str_replace(['.', ' '], '', $n);
    if ($n === '') {
        return '';
    }
    $ascii = strtr($n, ['ö' => 'o', 'ä' => 'a', 'å' => 'a', 'é' => 'e', 'Ö' => 'o', 'Ä' => 'a', 'Å' => 'a']);
    if (str_contains($n, 'eon')) {
        return 'E.ON';
    }
    if (str_contains($n, 'ellevio')) {
        return 'Ellevio';
    }
    if (str_contains($n, 'vattenfall')) {
        return 'Vattenfall';
    }
    if (str_contains($n, 'seom') || str_contains($n, 'sollentuna')) {
        return 'SEOM';
    }
    if (str_contains($n, 'kraftringen')) {
        return 'Kraftringen';
    }
    if (str_contains($n, 'öresundskraft') || str_contains($ascii, 'oresundskraft')) {
        return 'Öresundskraft';
    }
    return '';
}

function jeab_ordrar_natbolag_local_text(array $row): string
{
    $chunks = [];
    foreach (['huvudsokande_address', 'address', 'gata'] as $key) {
        $val = trim((string) ($row[$key] ?? ''));
        if ($val !== '' && !in_array($val, $chunks, true)) {
            $chunks[] = $val;
        }
    }
    $post = trim((string) ($row['postnr'] ?? $row['postnummer'] ?? ''));
    $ort = trim((string) ($row['ort'] ?? ''));
    if ($post !== '') {
        $chunks[] = $post;
    }
    if ($ort !== '') {
        $chunks[] = $ort;
    }
    return trim(preg_replace('/\s+/u', ' ', implode(' ', $chunks)) ?? '');
}

function jeab_ordrar_natbolag_address_text(array $row): string
{
    $text = jeab_ordrar_natbolag_local_text($row);
    if ($text === '') {
        return '';
    }
    $hay = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    if (!preg_match('/järfälla|jarfalla|malmö|malmo|sverige|sweden/u', $hay)) {
        $company = jeab_normalize_company($row['company'] ?? '') ?? '';
        if ($company === 'jarfalla') {
            $text .= ' Järfälla';
        } elseif ($company === 'malmo') {
            $text .= ' Malmö';
        }
    }
    if (!preg_match('/sverige|sweden/iu', $text)) {
        $text .= ' Sverige';
    }
    return trim($text);
}

function jeab_ordrar_http_json(string $url, int $timeout = 8): ?array
{
    if (!jeab_ordrar_external_live()) {
        return null;
    }
    if (function_exists('jeab_page_blocks_external') && jeab_page_blocks_external()) {
        return null;
    }
    $ua = 'JEAB-bokning/1.0 (ordrar natagare; +https://flowstack.example.test)';
    if (function_exists('jeab_pdo_release_if_idle')) {
        jeab_pdo_release_if_idle();
    }
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('http', $url)) {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $ua,
            ],
        ]);
        if (function_exists('jeab_pdo_release_if_idle')) {
            jeab_pdo_release_if_idle();
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $raw === '' || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\nUser-Agent: {$ua}\r\n",
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('http', $url)) {
        return null;
    }
    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function jeab_ordrar_geocode_point(string $address): ?array
{
    static $cache = [];
    $key = function_exists('mb_strtolower') ? mb_strtolower($address, 'UTF-8') : strtolower($address);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $url = 'https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?' . http_build_query([
        'singleLine' => $address,
        'f' => 'json',
        'maxLocations' => 1,
        'outSR' => 4326,
        'countryCode' => 'SE',
    ], '', '&', PHP_QUERY_RFC3986);
    $json = jeab_ordrar_http_json($url);
    $cands = is_array($json['candidates'] ?? null) ? $json['candidates'] : [];
    $first = is_array($cands[0] ?? null) ? $cands[0] : [];
    $loc = is_array($first['location'] ?? null) ? $first['location'] : [];
    $x = isset($loc['x']) ? (float) $loc['x'] : 0.0;
    $y = isset($loc['y']) ? (float) $loc['y'] : 0.0;
    $score = (float) ($first['score'] ?? 0);
    if ($x === 0.0 && $y === 0.0 || $score < 70) {
        $cache[$key] = null;
        return null;
    }
    $cache[$key] = ['x' => $x, 'y' => $y];
    return $cache[$key];
}

function jeab_ordrar_ei_lokalnat_query_url(): string
{
    static $url = null;
    if (is_string($url)) {
        return $url;
    }
    $known = 'https://services5.arcgis.com/Ffl7K2y4MZ3it6C6/arcgis/rest/services/Omradeskoncessioner_lokalnat_20260824/FeatureServer';
    $candidates = [$known];
    $picked = jeab_ordrar_ei_lokalnat_pick_query($candidates);
    if ($picked !== '') {
        $url = $picked;
        return $url;
    }
    $search = jeab_ordrar_http_json(
        'https://www.arcgis.com/sharing/rest/search?' . http_build_query([
            'q' => 'title:Omradeskoncessioner_lokalnat type:"Feature Service"',
            'f' => 'json',
            'sortField' => 'modified',
            'sortOrder' => 'desc',
            'num' => 5,
        ], '', '&', PHP_QUERY_RFC3986),
        6
    );
    foreach (is_array($search['results'] ?? null) ? $search['results'] : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $href = trim((string) ($item['url'] ?? ''));
        if ($href !== '' && !in_array($href, $candidates, true)) {
            $candidates[] = $href;
        }
    }
    $picked = jeab_ordrar_ei_lokalnat_pick_query($candidates);
    $url = $picked !== '' ? $picked : $known . '/2/query';
    return $url;
}

function jeab_ordrar_ei_lokalnat_pick_query(array $candidates): string
{
    foreach ($candidates as $base) {
        $base = trim((string) $base);
        if ($base === '') {
            continue;
        }
        $meta = jeab_ordrar_http_json(rtrim($base, '/') . '?f=json', 6);
        $layers = is_array($meta['layers'] ?? null) ? $meta['layers'] : [];
        $fallbackId = null;
        foreach ($layers as $layer) {
            if (!is_array($layer)) {
                continue;
            }
            $name = (string) ($layer['name'] ?? '');
            $id = (int) ($layer['id'] ?? -1);
            if ($id < 0) {
                continue;
            }
            if ($fallbackId === null) {
                $fallbackId = $id;
            }
            if (stripos($name, 'Omradeskoncessioner_lokalnat') !== false || stripos($name, 'lokalnat') !== false) {
                return rtrim($base, '/') . '/' . $id . '/query';
            }
        }
        if ($fallbackId !== null) {
            return rtrim($base, '/') . '/' . $fallbackId . '/query';
        }
    }
    return '';
}

function jeab_ordrar_natbolag_from_ei(array $point): string
{
    $query = jeab_ordrar_ei_lokalnat_query_url();
    if ($query === '') {
        return '';
    }
    // Kartlagret har bara FöretagNamn. En lista med påhittade alias ger 400 och inget träff.
    foreach (['FöretagNamn', '*'] as $outFields) {
        $rels = ['esriSpatialRelIntersects', 'esriSpatialRelWithin'];
        $sawFeature = false;
        foreach ($rels as $rel) {
            $url = $query . '?' . http_build_query([
                'geometry' => $point['x'] . ',' . $point['y'],
                'geometryType' => 'esriGeometryPoint',
                'inSR' => 4326,
                'spatialRel' => $rel,
                'outFields' => $outFields,
                'returnGeometry' => 'false',
                'f' => 'json',
            ], '', '&', PHP_QUERY_RFC3986);
            $json = jeab_ordrar_http_json($url);
            $feats = is_array($json['features'] ?? null) ? $json['features'] : [];
            foreach ($feats as $feat) {
                if (!is_array($feat)) {
                    continue;
                }
                $sawFeature = true;
                $attrs = is_array($feat['attributes'] ?? null) ? $feat['attributes'] : [];
                $name = trim((string) (
                    $attrs['FöretagNamn']
                    ?? $attrs['ForetagNamn']
                    ?? $attrs['foretagsnamn']
                    ?? $attrs['FORETAGNAMN']
                    ?? ''
                ));
                $chip = jeab_ordrar_natbolag_from_foretag($name);
                if ($chip !== '') {
                    return $chip;
                }
            }
        }
        if ($sawFeature) {
            return '';
        }
    }
    return '';
}

function jeab_ordrar_natbolag_postnummer_digits(array $row): string
{
    foreach (['postnr', 'postnummer'] as $key) {
        $digits = preg_replace('/\D+/', '', (string) ($row[$key] ?? '')) ?? '';
        if (preg_match('/^\d{5}$/', $digits) === 1) {
            return $digits;
        }
    }
    $hay = jeab_ordrar_natbolag_local_text($row);
    if (preg_match('/(?:^|\D)(\d{3})\s?(\d{2})(?:\D|$)/u', $hay, $m) === 1) {
        return $m[1] . $m[2];
    }
    return '';
}

/**
 * Postnummerprefix i JEAB-området.
 * 175–177 (hela Järfälla, inklusive 176 74 Kallhäll) är E.ON på varje
 * befintligt postnummer. Övriga prefix är stickprov där hela stickprovet
 * hade samma bolag. Blandade prefix (169, 173, 182) ger ingen träff.
 * 191–192 är Sollentuna Elnät (SEOM). Källa: EI områdeskoncessioner lokalnät 2026-08-24.
 */
function jeab_ordrar_natbolag_from_postnummer(string $digits): string
{
    if (preg_match('/^\d{5}$/', $digits) !== 1) {
        return '';
    }
    static $map = [
        '145' => 'Vattenfall',
        '146' => 'Vattenfall',
        '147' => 'Vattenfall',
        '163' => 'Ellevio',
        '164' => 'Ellevio',
        '165' => 'Ellevio',
        '167' => 'Ellevio',
        '168' => 'Ellevio',
        '175' => 'E.ON',
        '176' => 'E.ON',
        '177' => 'E.ON',
        '178' => 'Ellevio',
        '181' => 'Ellevio',
        '183' => 'Ellevio',
        '186' => 'Ellevio',
        '187' => 'Ellevio',
        '191' => 'SEOM',
        '192' => 'SEOM',
        '194' => 'E.ON',
        '196' => 'E.ON',
        '197' => 'E.ON',
    ];
    return $map[substr($digits, 0, 3)] ?? '';
}

function jeab_ordrar_natbolag_address_fallback(array $row): string
{
    $hay = jeab_ordrar_natbolag_local_text($row);
    $hay = function_exists('mb_strtolower') ? mb_strtolower($hay, 'UTF-8') : strtolower($hay);
    // Gatuundantag före postnummer: Dimvägen/Violvägen ligger i Järfälla men är namngivna E.ON.
    foreach ([
        'violvägen', 'violvagen',
        'dimvägen', 'dimvagen',
        'trehörningen', 'trehorningen',
    ] as $hint) {
        if ($hint !== '' && str_contains($hay, $hint)) {
            return 'E.ON';
        }
    }
    $fromPost = jeab_ordrar_natbolag_from_postnummer(jeab_ordrar_natbolag_postnummer_digits($row));
    if ($fromPost !== '') {
        return $fromPost;
    }
    // Vanlig sidvisning slår inte mot EI-kartan. Kommunordet Järfälla kommer sist
    // så ett mer specifikt område (Hässelby, Kallhäll) vinner. Bolagsorten som
    // bara läggs på för geokodning ingår inte i den här texten.
    // Sollentuna med kommundelar är SEOM. Eslöv, Hörby och Lomma är Kraftringen.
    // Helsingborg och Ängelholm är Öresundskraft.
    foreach ([
        'kallhäll' => 'E.ON',
        'kallhall' => 'E.ON',
        'barkarby' => 'E.ON',
        'jakobsberg' => 'E.ON',
        'viksjö' => 'E.ON',
        'viksjo' => 'E.ON',
        'stäket' => 'E.ON',
        'skälby' => 'E.ON',
        'skalby' => 'E.ON',
        'hässelby' => 'Ellevio',
        'hasselby' => 'Ellevio',
        'vällingby' => 'Ellevio',
        'vallingby' => 'Ellevio',
        'lidingö' => 'Ellevio',
        'lidingo' => 'Ellevio',
        'bromma' => 'Ellevio',
        'spånga' => 'Ellevio',
        'spanga' => 'Ellevio',
        'ekerö' => 'Ellevio',
        'ekero' => 'Ellevio',
        'grödinge' => 'Vattenfall',
        'norsborg' => 'Vattenfall',
        'sollentuna' => 'SEOM',
        'helenelund' => 'SEOM',
        'häggvik' => 'SEOM',
        'haggvik' => 'SEOM',
        'rotebro' => 'SEOM',
        'tureberg' => 'SEOM',
        'edsberg' => 'SEOM',
        'norrviken' => 'SEOM',
        'eslöv' => 'Kraftringen',
        'eslov' => 'Kraftringen',
        'hörby' => 'Kraftringen',
        'horby' => 'Kraftringen',
        'lomma' => 'Kraftringen',
        'helsingborg' => 'Öresundskraft',
        'ängelholm' => 'Öresundskraft',
        'angelholm' => 'Öresundskraft',
        'järfälla' => 'E.ON',
        'jarfalla' => 'E.ON',
    ] as $hint => $bolag) {
        if (str_contains($hay, $hint)) {
            return $bolag;
        }
    }
    $company = jeab_normalize_company($row['company'] ?? '') ?? '';
    if ($company === 'malmo') {
        return 'E.ON';
    }
    return '';
}

function jeab_ordrar_natbolag(array $row): string
{
    static $cache = [];
    $saved = trim((string) ($row['natbolag'] ?? ''));
    $savedOk = in_array($saved, jeab_ordrar_natbolag_options(), true);
    $addr = jeab_ordrar_natbolag_address_text($row);
    $cacheKey = ($savedOk ? $saved : '') . '|' . ($addr !== '' ? $addr : spl_object_id((object) $row) . ':' . (string) ($row['id'] ?? $row['reminder_id'] ?? ''));
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    // Sparat val vinner. Förslag fyller bara i när fältet är tomt, så ett
    // manuellt byte (även till Vattenfall i Järfälla) inte hoppar tillbaka.
    if ($savedOk) {
        $cache[$cacheKey] = $saved;
        return $saved;
    }
    if ($addr !== '') {
        $point = jeab_ordrar_geocode_point($addr);
        if ($point !== null) {
            $fromEi = jeab_ordrar_natbolag_from_ei($point);
            if ($fromEi !== '') {
                $cache[$cacheKey] = $fromEi;
                return $fromEi;
            }
        }
    }
    $chip = jeab_ordrar_natbolag_address_fallback($row);
    $cache[$cacheKey] = $chip;
    return $chip;
}

function jeab_ordrar_foranmalan_saved(array $row): bool
{
    return !empty($row['foranmalan_saved']) || trim((string) ($row['steps']['foranmald'] ?? '')) !== '';
}

function jeab_ordrar_orderrader_saved(array $row): bool
{
    return !empty($row['orderrader_saved']);
}

function jeab_ordrar_bestalld(array $row): bool
{
    return trim((string) ($row['steps']['bestalld'] ?? '')) !== '';
}

function jeab_ordrar_today(): string
{
    $cached = $GLOBALS['_JEAB_ORDRAR_TODAY'] ?? null;
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }
    $day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
    $GLOBALS['_JEAB_ORDRAR_TODAY'] = $day;

    return $day;
}

function jeab_ordrar_ensure_steps(array $row): array
{
    if (!is_array($row['steps'] ?? null)) {
        $row['steps'] = [];
    }
    return $row;
}

function jeab_ordrar_stamp_today(array $row, string $stepKey): array
{
    $row = jeab_ordrar_ensure_steps($row);
    if (trim((string) ($row['steps'][$stepKey] ?? '')) === '') {
        $row['steps'][$stepKey] = jeab_ordrar_today();
    }
    return $row;
}

function jeab_ordrar_stamp_bestalld_at(array $row): array
{
    $row = jeab_ordrar_stamp_today($row, 'bestalld');
    if (trim((string) ($row['bestalld_at'] ?? '')) === '') {
        $row['bestalld_at'] = jeab_now();
    }
    return $row;
}

function jeab_ordrar_wants_advance(array $input): bool
{
    $intent = strtolower(trim((string) ($input['intent'] ?? '')));
    return $intent === 'next' || $intent === 'nasta';
}

function jeab_ordrar_solar_next_stop(array $row, string $from): string
{
    $booked = jeab_ordrar_is_booked($row);
    $bestalld = jeab_ordrar_bestalld($row);
    if ($from === 'vad' || $from === 'bekrafta') {
        return 'spec';
    }
    if ($from === 'spec') {
        return 'orderrader';
    }
    if ($from === 'orderrader') {
        return 'bestall';
    }
    if ($from === 'boka' || $from === 'boka_elin' || $from === 'boka_bestall') {
        return $booked ? 'bestall' : 'boka';
    }
    if ($from === 'bestall') {
        return $bestalld ? 'fakturering' : 'bestall';
    }
    if ($from === 'eon') {
        // Stanna på Föranmälan så Skickad syns. Beställ är nästa steg i stepper.
        return 'eon';
    }
    if ($from === 'fardiganmal' || $from === 'fakturera' || $from === 'fakturering') {
        return 'fakturering';
    }
    return jeab_ordrar_default_stop($row);
}

function jeab_ordrar_bekrafta_date(array $row): string
{
    return jeab_ordrar_mmdd((string) ((is_array($row['steps'] ?? null) ? $row['steps'] : [])['bekraftad'] ?? ''));
}

function jeab_ordrar_spec_date(array $row): string
{
    return jeab_ordrar_mmdd((string) ((is_array($row['steps'] ?? null) ? $row['steps'] : [])['spec'] ?? ''));
}

function jeab_ordrar_spec_done(array $row): bool
{
    return jeab_ordrar_spec_date($row) !== '';
}

/**
 * Bokmalen har tagit emot föranmälan (övriga nätägare: steps.foranmald)
 * eller Ellevio-fullmakt (fullmakt.start).
 */
function jeab_ordrar_foranmalan_bokmalen_accepted(array $row): bool
{
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    if (trim((string) ($steps['foranmald'] ?? '')) !== '') {
        return true;
    }
    if (jeab_ordrar_foranmalan_case_status($row) === 'skickad') {
        return true;
    }
    if (trim((string) ($row['fullmakt_start_at'] ?? '')) !== '' || !empty($row['fullmakt_signed'])) {
        return true;
    }
    if (trim((string) ($row['seom_sam_sent_at'] ?? '')) !== '') {
        return true;
    }
    return false;
}

/**
 * Steget Föranmälan. Automatiskt (A) är klart först när Bokmalen tagit emot.
 * Utkast och Manuellt/ingen räknas när spec är sparad.
 */
function jeab_ordrar_foranmalan_wizard_done(array $row): bool
{
    if (jeab_ordrar_foranmalan_status($row) === 'A') {
        return jeab_ordrar_foranmalan_bokmalen_accepted($row);
    }
    return jeab_ordrar_spec_done($row);
}

function jeab_ordrar_orderrader_date(array $row): string
{
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    $raw = trim((string) ($steps['orderrader'] ?? ''));
    if ($raw === '') {
        $raw = trim((string) ($row['orderrader_saved_at'] ?? ''));
    }
    return jeab_ordrar_mmdd($raw);
}

function jeab_ordrar_fardiganmal_date(array $row): string
{
    return jeab_ordrar_mmdd((string) ((is_array($row['steps'] ?? null) ? $row['steps'] : [])['fardiganmald'] ?? ''));
}

/**
 * Knappen «Allt är fakturerat och klart, stäng ärendet» (ordrar.stang)
 * sätter allt_klart och closed_at. Den vägen är Allt klart utan övriga krav.
 */
function jeab_ordrar_manuellt_stangt(array $row): bool
{
    if (!empty($row['allt_klart'])) {
        return true;
    }
    return trim((string) ($row['closed_at'] ?? '')) !== '';
}

/** Föranmälan eller beställning är Manuell/ingen (val C). */
function jeab_ordrar_foranmalan_manuell(array $row): bool
{
    return jeab_ordrar_foranmalan_status($row) === 'C'
        || jeab_ordrar_bestallning_status($row) === 'C';
}

/**
 * Färdiganmälan: föranmälan är markerad Färdiganmäld i Ordrar.
 * Manuell/ingen hoppar över kravet.
 */
function jeab_ordrar_fardiganmalan_klar(array $row): bool
{
    if (jeab_ordrar_foranmalan_manuell($row)) {
        return true;
    }
    return jeab_ordrar_foranmalan_case_status($row) === 'fardiganmald';
}

/**
 * Samma läge som Status-kortet «DOKUMENTATION (Solkoll)»:
 * status Slutförd, eller steget 4/4. Andra produkter hoppar över kravet.
 */
function jeab_ordrar_solkoll_dokumentation_klar(array $row): bool
{
    if (jeab_ordrar_ui_kind($row['kind'] ?? '') !== 'sol') {
        return true;
    }
    $info = function_exists('jeab_solkoll_status_for_row')
        ? jeab_solkoll_status_for_row($row)
        : ['key' => '', 'done' => 0, 'total' => 0];
    if ((string) ($info['key'] ?? '') === 'slutford') {
        return true;
    }
    return (int) ($info['done'] ?? 0) === 4 && (int) ($info['total'] ?? 0) === 4;
}

/**
 * Auto-flytt till Allt klart. Alla villkor ska gälla:
 * riktig Fortnox-faktura, färdiganmälan (utom Manuell/ingen),
 * och för solceller Slutförd eller 4/4.
 * Fortnox-order och Fakturering «synkad» räcker inte.
 */
function jeab_ordrar_auto_allt_klart(array $row): bool
{
    if (!jeab_ordrar_has_invoice($row)) {
        return false;
    }
    if (!jeab_ordrar_fardiganmalan_klar($row)) {
        return false;
    }
    return jeab_ordrar_solkoll_dokumentation_klar($row);
}

function jeab_ordrar_allt_klart(array $row): bool
{
    if (jeab_ordrar_manual_done_on($row)) {
        return true;
    }
    if (jeab_ordrar_manuellt_stangt($row)) {
        return true;
    }
    return jeab_ordrar_auto_allt_klart($row);
}

/**
 * Manuell klarmarkering från ⋯-menyn. Bara flaggan, inget utskick.
 *
 * @return array{by:string,at:string}|null
 */
function jeab_ordrar_manual_done_record(array $row): ?array
{
    $done = $row['manual_done'] ?? null;
    if (!is_array($done)) {
        return null;
    }
    $by = trim((string) ($done['by'] ?? ''));
    $at = trim((string) ($done['at'] ?? ''));
    if ($by === '' || $at === '') {
        return null;
    }

    return ['by' => $by, 'at' => $at];
}

function jeab_ordrar_manual_done_on(array $row): bool
{
    return jeab_ordrar_manual_done_record($row) !== null;
}

function jeab_ordrar_manual_done_actor(): string
{
    if (function_exists('jeab_auth_user_person')) {
        $person = jeab_auth_user_person();
        if (is_array($person)) {
            $name = trim((string) ($person['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }
    $by = jeab_ordrar_deleted_actor();

    return $by !== '' ? $by : 'okänd';
}

function jeab_ordrar_manual_done_when(string $at): string
{
    $label = jeab_ordrar_status_datetime_label($at);

    return $label !== '' ? $label : trim($at);
}

function jeab_ordrar_manual_done_log(string $who, string $when, bool $undo): string
{
    $who = trim($who);
    if ($who === '') {
        $who = 'okänd';
    }
    $when = trim($when);
    if ($undo) {
        return 'Klarmarkering ångrad av ' . $who . ($when !== '' ? ' ' . $when : '');
    }

    return 'Manuellt klarmarkerad av ' . $who . ($when !== '' ? ' ' . $when : '');
}

function jeab_ordrar_status_date_label(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $day = jeab_ordrar_day($raw);
    if (!$day instanceof DateTimeImmutable) {
        try {
            $day = new DateTimeImmutable($raw, new DateTimeZone('Europe/Stockholm'));
        } catch (Exception) {
            return '';
        }
    }
    $month = function_exists('jeab_sv_month_short') ? jeab_sv_month_short($day) : $day->format('M');
    return (int) $day->format('j') . ' ' . $month . ' ' . $day->format('Y');
}

function jeab_ordrar_status_datetime_label(string $raw): string
{
    $raw = trim($raw);
    $date = jeab_ordrar_status_date_label($raw);
    if ($date === '') {
        return '';
    }
    if (preg_match('/(?:T|\s)(\d{2}):(\d{2})/', $raw, $m) === 1 && !($m[1] === '00' && $m[2] === '00')) {
        return $date . ' ' . $m[1] . ':' . $m[2];
    }
    return $date;
}

/** @return array{street:string,postnummer:string,place:string} */
function jeab_ordrar_status_address_lines(array $row): array
{
    $copy = jeab_ordrar_status_address_stored($row);
    if (!function_exists('jeab_contacts_sole_view_for')) {
        return $copy;
    }
    $view = jeab_contacts_sole_view_for($row);
    if (!is_array($view)) {
        return $copy;
    }
    $post = $view['postal_fmt'] !== '' ? $view['postal_fmt'] : $view['postal'];
    // Saknad kontakt: samma kopia som tidigare. Länkad kontakt visas som den är,
    // även när gata, postnummer eller ort är tomma.
    if (empty($view['linked'])) {
        if ($view['street'] !== '' || $post !== '' || $view['city'] !== '') {
            return [
                'street' => $view['street'],
                'postnummer' => $post,
                'place' => $view['city'],
            ];
        }

        return $copy;
    }
    if (function_exists('jeab_contacts_sole_log_order_only')) {
        $orderStreet = trim((string) ($row['gata'] ?? ''));
        if ($orderStreet === '') {
            $orderStreet = trim((string) ($row['address'] ?? $row['huvudsokande_address'] ?? ''));
        }
        if ($view['street'] === '' && $orderStreet !== '') {
            jeab_contacts_sole_log_order_only($row, 'address');
        }
        $orderPost = trim((string) ($row['postnummer'] ?? $row['postnr'] ?? ''));
        if ($post === '' && $orderPost !== '') {
            jeab_contacts_sole_log_order_only($row, 'address');
        }
        if ($view['city'] === '' && trim((string) ($row['ort'] ?? '')) !== '') {
            jeab_contacts_sole_log_order_only($row, 'address');
        }
    }

    return [
        'street' => (string) $view['street'],
        'postnummer' => $post,
        'place' => (string) $view['city'],
    ];
}

/** @return array{street:string,postnummer:string,place:string} */
function jeab_ordrar_status_address_stored(array $row): array
{
    $raw = '';
    foreach (['huvudsokande_address', 'address', 'gata'] as $key) {
        $val = trim((string) ($row[$key] ?? ''));
        if ($val !== '') {
            $raw = $val;
            break;
        }
    }
    $street = '';
    $post = '';
    $ort = '';
    if ($raw !== '' && function_exists('jeab_ellevio_split_address')) {
        $parts = jeab_ellevio_split_address($raw);
        $street = trim((string) ($parts['adress'] ?? ''));
        $post = trim((string) ($parts['postnummer'] ?? ''));
        $ort = trim((string) ($parts['ort'] ?? ''));
    }
    if ($post === '' && $ort === '' && str_contains($raw, ',')) {
        [$left, $right] = array_map('trim', explode(',', $raw, 2));
        if ($left !== '' && $right !== '' && preg_match('/\d/u', $right) !== 1) {
            $street = $left;
            $ort = $right;
        }
    }
    if ($street === '' && $raw !== '' && function_exists('jeab_reminder_street')) {
        $street = trim(jeab_reminder_street($raw));
    }
    if ($street === '') {
        $street = $raw;
    }
    $gata = trim((string) ($row['gata'] ?? ''));
    if ($gata !== '' && ($street === $raw || str_contains($street, ','))) {
        $street = $gata;
    }
    if ($post === '') {
        $post = trim((string) ($row['postnr'] ?? $row['postnummer'] ?? ''));
    }
    if ($ort === '') {
        $ort = trim((string) ($row['ort'] ?? ''));
    }
    $digits = jeab_ordrar_postnummer_digits($post);
    if ($digits !== '') {
        $post = jeab_ordrar_format_postnummer($digits);
    }
    return [
        'street' => $street,
        'postnummer' => $post,
        'place' => $ort,
    ];
}

function jeab_ordrar_fold_address(string $text): string
{
    return jeab_norm_fold($text);
}

function jeab_ordrar_postnummer_digits(string $raw): string
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

function jeab_ordrar_format_postnummer(string $digits): string
{
    $digits = jeab_ordrar_postnummer_digits($digits);
    if ($digits === '') {
        return '';
    }
    return substr($digits, 0, 3) . ' ' . substr($digits, 3);
}

/**
 * Sista reserv om Lantmäteriet-uppslaget inte gav postnummer.
 * Hässelby Strandväg 47 (order #23 Adolfsson) ska bli 165 65 när fältet är tomt.
 */
function jeab_ordrar_known_postnummer(array $row, ?array $reminder = null): string
{
    $blob = '';
    foreach (['huvudsokande_address', 'address', 'gata'] as $key) {
        $blob .= ' ' . (string) ($row[$key] ?? '');
    }
    if (is_array($reminder)) {
        $blob .= ' ' . (string) ($reminder['address'] ?? '');
        $blob .= ' ' . (string) ($reminder['title'] ?? '');
    }
    $folded = jeab_ordrar_fold_address($blob);
    if (preg_match('/hasselby strandvag\s+47\b/u', $folded) === 1) {
        return '16565';
    }
    return '';
}

function jeab_ordrar_address_blob(array $row, ?array $reminder = null): string
{
    $parts = [];
    foreach (['huvudsokande_address', 'address', 'gata'] as $key) {
        $val = trim((string) ($row[$key] ?? ''));
        if ($val !== '') {
            $parts[] = $val;
        }
    }
    if (is_array($reminder)) {
        foreach (['address', 'title'] as $key) {
            $val = trim((string) ($reminder[$key] ?? ''));
            if ($val !== '') {
                $parts[] = $val;
            }
        }
    }
    return implode(' | ', $parts);
}

/**
 * Skriver postnummer på ordern. Ett redan ifyllt postnummer lämnas orört.
 * $replaceZip byter ut ett annat postnummer i adressraden (Lantmäteriet).
 *
 * @return array{0:array,1:bool}
 */
function jeab_ordrar_assign_postnummer(array $row, string $digits, string $ort, bool $replaceZip): array
{
    $digits = jeab_ordrar_postnummer_digits($digits);
    if ($digits === '') {
        return [$row, false];
    }
    $existing = jeab_ordrar_postnummer_digits((string) ($row['postnummer'] ?? ''));
    if ($existing === '') {
        $existing = jeab_ordrar_postnummer_digits((string) ($row['postnr'] ?? ''));
    }
    if ($existing !== '' && $existing !== $digits) {
        return [$row, false];
    }
    $changed = false;
    $formatted = jeab_ordrar_format_postnummer($digits);
    if ((string) ($row['postnummer'] ?? '') !== $digits) {
        $row['postnummer'] = $digits;
        $changed = true;
    }
    if ((string) ($row['postnr'] ?? '') !== $formatted) {
        $row['postnr'] = $formatted;
        $changed = true;
    }
    if ($ort !== '' && ($replaceZip || trim((string) ($row['ort'] ?? '')) === '')) {
        if (trim((string) ($row['ort'] ?? '')) !== $ort) {
            $row['ort'] = $ort;
            $changed = true;
        }
    } else {
        $ort = trim((string) ($row['ort'] ?? ''));
    }
    $address = trim((string) ($row['huvudsokande_address'] ?? ''));
    if ($address === '') {
        $address = trim((string) ($row['address'] ?? ''));
    }
    $addressDigits = jeab_ordrar_postnummer_digits($address);
    if ($address !== '' && $addressDigits !== '' && !$replaceZip) {
        return [$row, $changed];
    }
    if ($address === '' && trim((string) ($row['gata'] ?? '')) === '') {
        return [$row, $changed];
    }
    $street = trim((string) ($row['gata'] ?? ''));
    if ($street === '' && $address !== '' && function_exists('jeab_ellevio_split_address')) {
        $parts = jeab_ellevio_split_address($address);
        $street = trim((string) ($parts['adress'] ?? ''));
        if ($ort === '') {
            $ort = trim((string) ($parts['ort'] ?? ''));
        }
    }
    if ($street === '' && str_contains($address, ',')) {
        $street = trim(explode(',', $address, 2)[0]);
    }
    if ($street === '') {
        $street = $address;
    }
    if ($street !== '' && str_contains($street, ',') && jeab_ordrar_postnummer_digits($street) === '') {
        [$left, $right] = array_map('trim', explode(',', $street, 2));
        if ($left !== '' && $right !== '' && preg_match('/\d/u', $right) !== 1) {
            $street = $left;
        }
    }
    if ($ort === '' && $address !== '' && str_contains($address, ',')) {
        $tail = trim(explode(',', $address, 2)[1]);
        if ($tail !== '' && preg_match('/\d/u', $tail) !== 1) {
            $ort = $tail;
        }
    }
    if ($ort !== '' && trim((string) ($row['ort'] ?? '')) === '') {
        $row['ort'] = $ort;
        $changed = true;
    }
    $place = trim($formatted . ($ort !== '' ? ' ' . $ort : ''));
    $full = jeab_ordrar_compose_objekt_address($street, $place);
    if ($full !== '' && $full !== $address) {
        $row['address'] = $full;
        $row['huvudsokande_address'] = $full;
        if (trim((string) ($row['gata'] ?? '')) === '') {
            $row['gata'] = $street;
        }
        $changed = true;
    }
    return [$row, $changed];
}

/**
 * Postnummer från adressuppslaget. Skriver inte över ett redan sparat värde.
 *
 * @return array{0:array,1:bool}
 */
function jeab_ordrar_apply_lookup_postnummer(array $row, array $lookup): array
{
    $existing = jeab_ordrar_postnummer_digits((string) ($row['postnummer'] ?? ''));
    if ($existing === '') {
        $existing = jeab_ordrar_postnummer_digits((string) ($row['postnr'] ?? ''));
    }
    if ($existing !== '') {
        return [$row, false];
    }
    $digits = jeab_ordrar_postnummer_digits((string) ($lookup['postnummer'] ?? ''));
    $ort = trim((string) ($lookup['ort'] ?? ''));
    if ($digits === '' || $ort === '') {
        $blob = trim((string) ($lookup['address'] ?? ''));
        if ($blob !== '' && function_exists('jeab_ellevio_split_address')) {
            $parts = jeab_ellevio_split_address($blob);
            if ($digits === '') {
                $digits = jeab_ordrar_postnummer_digits((string) ($parts['postnummer'] ?? ''));
            }
            if ($ort === '') {
                $ort = trim((string) ($parts['ort'] ?? ''));
            }
        }
    }
    if ($digits === '') {
        return [$row, false];
    }
    return jeab_ordrar_assign_postnummer($row, $digits, $ort, true);
}

/**
 * Sista reserv: adressrad, leadtext, sedan känd adress.
 * Lantmäteriet ska ha prövats först. Ett redan sparat postnummer lämnas orört.
 *
 * @return array{0:array,1:bool}
 */
function jeab_ordrar_fill_postnummer(array $row, ?array $reminder = null): array
{
    $digits = jeab_ordrar_postnummer_digits((string) ($row['postnummer'] ?? ''));
    if ($digits === '') {
        $digits = jeab_ordrar_postnummer_digits((string) ($row['postnr'] ?? ''));
    }
    if ($digits !== '') {
        return jeab_ordrar_assign_postnummer($row, $digits, trim((string) ($row['ort'] ?? '')), false);
    }
    $ort = trim((string) ($row['ort'] ?? ''));
    $sources = [];
    foreach (['huvudsokande_address', 'address', 'gata'] as $key) {
        $sources[] = (string) ($row[$key] ?? '');
    }
    if (is_array($reminder)) {
        $sources[] = (string) ($reminder['address'] ?? '');
        $sources[] = (string) ($reminder['title'] ?? '');
    }
    foreach ($sources as $src) {
        if (!function_exists('jeab_ellevio_split_address')) {
            break;
        }
        $parts = jeab_ellevio_split_address($src);
        $found = jeab_ordrar_postnummer_digits((string) ($parts['postnummer'] ?? ''));
        if ($found === '') {
            continue;
        }
        $digits = $found;
        if ($ort === '') {
            $ort = trim((string) ($parts['ort'] ?? ''));
        }
        break;
    }
    if ($digits === '') {
        $digits = jeab_ordrar_known_postnummer($row, $reminder);
        if ($digits !== '' && $ort === '') {
            $loose = jeab_ordrar_address_blob($row, $reminder);
            if (preg_match('/,\s*([^,\d][^,]*)\s*$/u', $loose, $m) === 1) {
                $ort = trim($m[1]);
            } elseif ($digits === '16565') {
                $ort = 'Hässelby';
            }
        }
    }
    if ($digits === '') {
        return [$row, false];
    }
    return jeab_ordrar_assign_postnummer($row, $digits, $ort, false);
}

/**
 * Lantmäteriet först. Lead och känd adress bara om uppslaget saknar postnummer.
 * $lookup null gör uppslaget (tomt när live är av). Skickat uppslag används som det är.
 */
function jeab_ordrar_persist_postnummer(array $row, ?array $reminder = null, ?array $lookup = null): array
{
    $existing = jeab_ordrar_postnummer_digits((string) ($row['postnummer'] ?? ''));
    if ($existing === '') {
        $existing = jeab_ordrar_postnummer_digits((string) ($row['postnr'] ?? ''));
    }
    if ($existing !== '') {
        return $row;
    }
    if ($lookup === null) {
        $query = jeab_ordrar_lookup_query(is_array($reminder) ? $reminder : null, $row);
        $lookup = jeab_ordrar_address_lookup($query);
    }
    [$filled, $changed] = jeab_ordrar_apply_lookup_postnummer($row, $lookup);
    if (!$changed) {
        [$filled, $changed] = jeab_ordrar_fill_postnummer($row, $reminder);
    }
    if (!$changed) {
        return $row;
    }
    $id = (int) ($filled['reminder_id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_purchase_get') || !is_array(jeab_purchase_get($id)) || !function_exists('jeab_purchase_put')) {
        return $filled;
    }
    $filled['updated_at'] = function_exists('jeab_now') ? jeab_now() : ($filled['updated_at'] ?? '');
    return jeab_purchase_put($filled);
}

function jeab_ordrar_status_natbolag(array $row): string
{
    $saved = trim((string) ($row['natbolag'] ?? ''));
    if (in_array($saved, jeab_ordrar_natbolag_options(), true)) {
        return $saved;
    }
    return trim(jeab_ordrar_natbolag($row));
}

function jeab_ordrar_status_comment(array $row): string
{
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    foreach (['foranmalan_kommentar', 'foranmalan_comment', 'foranmalan_note'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
        $value = trim((string) ($steps[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function jeab_ordrar_status_cal_svg(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3.5" y="5" width="17" height="15.5" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 10h17M8 3.5v3M16 3.5v3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
}

/** @return array{post:string,ort:string} */
function jeab_ordrar_parse_postort(string $place): array
{
    $place = trim(preg_replace('/\s+/u', ' ', $place) ?? $place);
    if (preg_match('/^(\d{3}\s?\d{2})\s+(\S.*)$/u', $place, $m) === 1) {
        $digits = preg_replace('/\s+/', '', $m[1]) ?? '';
        $post = strlen($digits) === 5 ? substr($digits, 0, 3) . ' ' . substr($digits, 3) : trim($m[1]);
        return ['post' => $post, 'ort' => trim($m[2])];
    }
    return ['post' => '', 'ort' => $place];
}

function jeab_ordrar_compose_objekt_address(string $street, string $place): string
{
    $street = trim(preg_replace('/\s+/u', ' ', $street) ?? $street);
    $place = trim(preg_replace('/\s+/u', ' ', $place) ?? $place);
    if ($street === '') {
        return $place;
    }
    if ($place === '') {
        return $street;
    }
    return $street . ', ' . $place;
}

/** @return array{phone:string,email:string} */
function jeab_ordrar_status_reminder_contact(array $row, int $openId = 0): array
{
    $empty = ['phone' => '', 'email' => ''];
    if (!function_exists('jeab_ordrar_reminder_for_row')) {
        return $empty;
    }
    try {
        $reminder = jeab_ordrar_reminder_for_row($row, $openId);
    } catch (Throwable) {
        return $empty;
    }
    if (!is_array($reminder)) {
        return $empty;
    }
    $phone = function_exists('jeab_reminder_phone')
        ? jeab_reminder_phone($reminder)
        : trim((string) ($reminder['phone'] ?? ''));
    $email = function_exists('jeab_reminder_email')
        ? jeab_reminder_email($reminder)
        : trim((string) ($reminder['email'] ?? ''));
    return ['phone' => trim($phone), 'email' => trim($email)];
}

/** @return array{phone:string,email:string} */
function jeab_ordrar_status_contact(array $row, int $openId = 0): array
{
    $copy = jeab_ordrar_status_contact_stored($row, $openId);
    if (!function_exists('jeab_contacts_sole_view_for')) {
        return $copy;
    }
    $view = jeab_contacts_sole_view_for($row);
    if (!is_array($view)) {
        return $copy;
    }
    if (empty($view['linked'])) {
        if ($view['phone'] !== '' || $view['email'] !== '') {
            return ['phone' => $view['phone'], 'email' => $view['email']];
        }

        return $copy;
    }
    if (function_exists('jeab_contacts_sole_log_order_only')) {
        if (trim((string) $view['phone']) === '' && trim((string) ($row['phone'] ?? '')) !== '') {
            jeab_contacts_sole_log_order_only($row, 'phone');
        }
        if (trim((string) $view['email']) === '' && trim((string) ($row['email'] ?? '')) !== '') {
            jeab_contacts_sole_log_order_only($row, 'email');
        }
    }

    return [
        'phone' => (string) $view['phone'],
        'email' => (string) $view['email'],
    ];
}

/** @return array{phone:string,email:string} */
function jeab_ordrar_status_contact_stored(array $row, int $openId = 0): array
{
    $phone = trim((string) ($row['phone'] ?? ''));
    $email = trim((string) ($row['email'] ?? ''));
    $locked = trim((string) ($row['objekt_saved_at'] ?? '')) !== '';
    if ($locked) {
        return ['phone' => $phone, 'email' => $email];
    }
    $applicant = is_array($row['applicant'] ?? null) ? $row['applicant'] : [];
    if ($phone === '') {
        $phone = trim((string) ($applicant['phone'] ?? ''));
    }
    if ($email === '') {
        $email = trim((string) ($applicant['email'] ?? ''));
    }
    if ($phone === '' || $email === '') {
        $fromReminder = jeab_ordrar_status_reminder_contact($row, $openId);
        if ($phone === '') {
            $phone = $fromReminder['phone'];
        }
        if ($email === '') {
            $email = $fromReminder['email'];
        }
    }
    return ['phone' => $phone, 'email' => $email];
}

function jeab_ordrar_keep_status_stop(string $action, string $stop): string
{
    if ($action === 'ordrar.objekt') {
        return 'status';
    }
    return $stop;
}

/**
 * Personnummer från Objekt. Tomt = tomt. 10 eller 12 siffror, med eller utan
 * bindestreck, blir ÅÅÅÅMMDD-XXXX. Sekel från jeab_norm_pnr_digits.
 * Luhn krävs inte här (ogiltig kontrollsiffra får rättas). Kanonisk kontroll är jeab_norm_pnr.
 */
function jeab_ordrar_normalize_objekt_pnr(string $raw): string
{
    $raw = jeab_idnr_clean(trim($raw));
    if ($raw === '') {
        return '';
    }
    $compact = preg_replace('/\s+/u', '', $raw) ?? $raw;
    if (preg_match('/^\d+(?:[-+]\d+)?$/', $compact) !== 1) {
        throw new InvalidArgumentException('Personnummer får bara ha siffror och ett bindestreck (ÅÅÅÅMMDD-XXXX).');
    }
    $len = strlen(preg_replace('/\D+/', '', $compact) ?? '');
    $digits = jeab_norm_pnr_digits($compact);
    if (strlen($digits) !== 12) {
        throw new InvalidArgumentException('Personnummer ska vara 12 siffror, ÅÅÅÅMMDD-XXXX (fick ' . $len . ' siffror).');
    }
    $month = (int) substr($digits, 4, 2);
    $day = (int) substr($digits, 6, 2);
    $dayOk = ($day >= 1 && $day <= 31) || ($day >= 61 && $day <= 91);
    if ($month < 1 || $month > 12 || !$dayOk) {
        throw new InvalidArgumentException('Personnummer ska börja med ett datum, ÅÅÅÅMMDD-XXXX.');
    }
    return substr($digits, 0, 8) . '-' . substr($digits, 8);
}

/** Fält vars ursprung sparas i row['identity_source']. */
function jeab_ordrar_identity_source_fields(): array
{
    return ['personnummer', 'fastighetsbeteckning', 'postnummer'];
}

/** Jämförbart värde för ett identitetsfält (pnr och postnummer som siffror). */
function jeab_ordrar_identity_value(array $row, string $field): string
{
    if ($field === 'postnummer') {
        $zip = jeab_ordrar_postnummer_digits((string) ($row['postnummer'] ?? ''));
        return $zip !== '' ? $zip : jeab_ordrar_postnummer_digits((string) ($row['postnr'] ?? ''));
    }
    $value = trim((string) ($row[$field] ?? ''));
    if ($field === 'personnummer') {
        // Sista tio siffrorna: 10 och 12 siffror (med/utan sekel) är samma nummer.
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }
    return jeab_ordrar_fold_address($value);
}

/**
 * Var värdet kommer ifrån: '' (tomt), 'lookup' (ifyllt automatiskt) eller 'manual'.
 * Värde utan markering, eller där markeringen gäller ett annat värde, räknas som manuellt.
 */
function jeab_ordrar_identity_source(array $row, string $field): string
{
    $value = jeab_ordrar_identity_value($row, $field);
    if ($value === '') {
        return '';
    }
    $mark = is_array($row['identity_source'][$field] ?? null) ? $row['identity_source'][$field] : [];
    if ((string) ($mark['src'] ?? '') === 'lookup' && (string) ($mark['value'] ?? '') === $value) {
        return 'lookup';
    }
    return 'manual';
}

/** Markera ett fälts ursprung. Tom $src tar bort markeringen. */
function jeab_ordrar_mark_identity_source(array $row, string $field, string $src, string $address = ''): array
{
    $marks = is_array($row['identity_source'] ?? null) ? $row['identity_source'] : [];
    $value = jeab_ordrar_identity_value($row, $field);
    if ($src === '' || $value === '') {
        unset($marks[$field]);
    } else {
        $marks[$field] = [
            'src' => $src === 'lookup' ? 'lookup' : 'manual',
            'value' => $value,
            'address' => $address,
            'at' => jeab_now(),
        ];
    }
    if ($marks === []) {
        $row['identity_source'] = null;
    } else {
        $row['identity_source'] = $marks;
    }
    return $row;
}

/** Fält som var tomma i $before och fylldes automatiskt markeras som uppslag. */
function jeab_ordrar_mark_lookup_fills(array $before, array $after, string $address): array
{
    foreach (jeab_ordrar_identity_source_fields() as $field) {
        $was = jeab_ordrar_identity_value($before, $field);
        $now = jeab_ordrar_identity_value($after, $field);
        if ($now !== '' && $now !== $was && ($was === '' || jeab_ordrar_identity_source($before, $field) === 'lookup')) {
            $after = jeab_ordrar_mark_identity_source($after, $field, 'lookup', $address);
        }
    }
    return $after;
}

/**
 * Sparat fält från ett formulär. Oförändrat värde behåller sin markering.
 * Värde lika med det föreslagna (uppslaget som visades) blir 'lookup', annat blir 'manual'.
 */
function jeab_ordrar_mark_posted_identity(array $before, array $row, string $field, string $suggested, string $address): array
{
    $now = jeab_ordrar_identity_value($row, $field);
    if ($now === '') {
        return jeab_ordrar_mark_identity_source($row, $field, '');
    }
    if ($now === jeab_ordrar_identity_value($before, $field)) {
        $beforeMarks = is_array($before['identity_source'] ?? null) ? $before['identity_source'] : [];
        if (is_array($beforeMarks[$field] ?? null)) {
            $rowMarks = is_array($row['identity_source'] ?? null) ? $row['identity_source'] : [];
            $rowMarks[$field] = $beforeMarks[$field];
            $row['identity_source'] = $rowMarks;
        }
        return $row;
    }
    $sugg = jeab_ordrar_identity_value([$field => $suggested], $field);
    return jeab_ordrar_mark_identity_source($row, $field, $sugg !== '' && $sugg === $now ? 'lookup' : 'manual', $address);
}

/** Gata och postnummer, vikta för jämförelse. Tom när ordern saknar adress. */
function jeab_ordrar_address_key(array $row): string
{
    $lines = jeab_ordrar_status_address_lines($row);
    $street = (string) ($lines['street'] ?? '');
    $zip = jeab_ordrar_postnummer_digits((string) ($lines['postnummer'] ?? ''));
    $town = (string) ($lines['place'] ?? '');
    $norm = jeab_norm_street_key($street, $zip, $town);
    if ($norm !== '') {
        return $norm;
    }
    $folded = trim(preg_replace('/[\s,.]+/u', ' ', jeab_norm_fold($street)) ?? '');
    if ($folded === '' && $zip === '') {
        return '';
    }
    return $folded . '|' . $zip;
}

/**
 * Fastighet och personnummer från ett uppslag. Bara tomma eller automatiska fält.
 * Personnummer bara när en ägare från uppslaget matchats (samma regler som Bekräfta, #251).
 */
function jeab_ordrar_apply_lookup_identity(array $row, array $suggest, string $query): array
{
    if (!empty($suggest['lookup_empty'])) {
        return $row;
    }
    $replaceable = static fn(array $r, string $f): bool => jeab_ordrar_identity_source($r, $f) !== 'manual';
    $fast = jeab_ordrar_format_beteckning((string) ($suggest['fastighetsbeteckning'] ?? ''));
    if ($fast !== '' && $replaceable($row, 'fastighetsbeteckning') && jeab_ordrar_identity_value($row, 'fastighetsbeteckning') !== jeab_ordrar_fold_address($fast)) {
        $row['fastighetsbeteckning'] = $fast;
        $row = jeab_ordrar_mark_identity_source($row, 'fastighetsbeteckning', 'lookup', $query);
    }
    $pnr = jeab_ordrar_format_pnr((string) ($suggest['personnummer'] ?? ''));
    $pnrDigits = preg_replace('/\D+/', '', $pnr) ?? '';
    $fromOwners = false;
    foreach (is_array($suggest['owners'] ?? null) ? $suggest['owners'] : [] as $owner) {
        if ($pnrDigits !== '' && (preg_replace('/\D+/', '', (string) ($owner['personnummer'] ?? '')) ?? '') === $pnrDigits) {
            $fromOwners = true;
            break;
        }
    }
    $matched = !empty($suggest['name_matched']) || jeab_ordrar_honor_huvudsokande_pick($row);
    if ($fromOwners && $matched && $replaceable($row, 'personnummer')
        && jeab_ordrar_identity_value($row, 'personnummer') !== jeab_ordrar_identity_value(['personnummer' => $pnr], 'personnummer')) {
        $row['personnummer'] = $pnr;
        $row = jeab_ordrar_mark_identity_source($row, 'personnummer', 'lookup', $query);
        $huvud = trim((string) ($suggest['huvudsokande'] ?? ''));
        if ($huvud !== '' && trim((string) ($row['huvudsokande'] ?? '')) === '') {
            $row['huvudsokande'] = $huvud;
        }
    }
    return $row;
}

/** Gata och husnummer, vikt. «Genjsvägen 15A, 187 30 Täby» och «genjsvägen 15 a täby» ger samma. */
function jeab_ordrar_street_sig(string $address): string
{
    $fold = jeab_ordrar_fold_address($address);
    $fold = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $fold) ?? $fold;
    $tokens = array_values(array_filter(preg_split('/\s+/u', trim($fold)) ?: [], static fn(string $x): bool => $x !== ''));
    $out = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (preg_match('/\d/u', $tok) === 1) {
            if ($i + 1 < $count && preg_match('/^\p{L}$/u', $tokens[$i + 1]) === 1) {
                $tok .= $tokens[$i + 1];
            }
            $out[] = $tok;
            break;
        }
        $out[] = $tok;
    }
    return implode(' ', $out);
}

/** Signatur för en ägarpost: namn, personnummer (tio siffror) och gata. */
function jeab_ordrar_owner_sig(mixed $person): string
{
    $p = jeab_ordrar_person($person);
    $digits = preg_replace('/\D+/', '', (string) $p['personnummer']) ?? '';
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }
    return jeab_ordrar_name_fold((string) $p['name']) . '|' . $digits . '|' . jeab_ordrar_street_sig((string) ($p['address'] ?? ''));
}

/** Gator ordern haft: före ändringen, leadens adress (det gamla uppslaget sökte på den) och sparad föregående adress. */
function jeab_ordrar_old_streets(array $row, ?array $reminder = null): array
{
    $raws = [];
    foreach (['address', 'huvudsokande_address', 'gata', 'address_previous'] as $key) {
        $raws[] = (string) ($row[$key] ?? '');
    }
    if (is_array($reminder)) {
        $raws[] = (string) ($reminder['address'] ?? '');
        $raws[] = (string) ($reminder['title'] ?? '');
        $raws[] = jeab_ordrar_lookup_query($reminder, []);
    }
    $out = [];
    foreach ($raws as $raw) {
        $sig = jeab_ordrar_street_sig($raw);
        if ($sig !== '' && preg_match('/\d/u', $sig) === 1 && !in_array($sig, $out, true)) {
            $out[] = $sig;
        }
    }
    return $out;
}

/**
 * Är ägarposten automatisk (får ersättas av ett nytt uppslag)?
 * - Markerad: posten finns bland uppslagets poster i identity_source['owners'].
 * - Ingen markering alls på ordern (äldre data): bara platshållaren som ett tomt uppslag gav,
 *   alltså utan personnummer, med kundens/huvudsökandes namn (eller tomt) och en adress ordern haft.
 * Allt annat räknas som handskrivet.
 */
function jeab_ordrar_owner_entry_auto(array $row, mixed $person, array $knownStreets): bool
{
    $mark = is_array($row['identity_source']['owners'] ?? null) ? $row['identity_source']['owners'] : null;
    $sig = jeab_ordrar_owner_sig($person);
    if ($mark !== null) {
        return in_array($sig, is_array($mark['entries'] ?? null) ? $mark['entries'] : [], true);
    }
    $p = jeab_ordrar_person($person);
    if ($p['personnummer'] !== '') {
        return false;
    }
    $name = (string) $p['name'];
    $nameOk = $name === '';
    foreach ([(string) ($row['huvudsokande'] ?? ''), jeab_ordrar_customer_name($row), (string) ($row['name'] ?? '')] as $known) {
        if ($nameOk || trim($known) === '') {
            continue;
        }
        $nameOk = jeab_ordrar_same_person($name, $known)
            || jeab_ordrar_owner_fits_customer($name, $known)
            || jeab_ordrar_owner_fits_customer($known, $name);
    }
    $addr = trim((string) ($p['address'] ?? ''));
    $addrOk = $addr === '' || in_array(jeab_ordrar_street_sig($addr), $knownStreets, true);
    return $nameOk && $addrOk;
}

/**
 * Matchar två ägarposter: samma personnummer (tio siffror) eller samma/överlappande namn.
 */
function jeab_ordrar_owners_same_person(mixed $a, mixed $b): bool
{
    $pa = jeab_ordrar_person($a);
    $pb = jeab_ordrar_person($b);
    $da = jeab_ordrar_identity_value(['personnummer' => $pa['personnummer']], 'personnummer');
    $db = jeab_ordrar_identity_value(['personnummer' => $pb['personnummer']], 'personnummer');
    if ($da !== '' && $db !== '' && $da === $db) {
        return true;
    }
    $na = trim((string) $pa['name']);
    $nb = trim((string) $pb['name']);
    if ($na === '' || $nb === '') {
        return false;
    }
    return jeab_ordrar_same_person($na, $nb)
        || jeab_ordrar_owner_fits_customer($na, $nb)
        || jeab_ordrar_owner_fits_customer($nb, $na);
}

/**
 * Samma person: personnummer, exakt namn, för- och efternamn, eller kort namn
 * mot Lantmäteriets längre namn. Ett ensamt förnamn räcker bara när det ingår
 * i det längre namnet.
 *
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function jeab_ordrar_person_matches(array $a, array $b): bool
{
    if (jeab_ordrar_owners_same_person($a, $b)) {
        return true;
    }

    return jeab_ordrar_name_covers((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
        || jeab_ordrar_name_covers((string) ($b['name'] ?? ''), (string) ($a['name'] ?? ''));
}

function jeab_ordrar_name_covers(string $short, string $long): bool
{
    $ps = jeab_ordrar_name_parts($short);
    $pl = jeab_ordrar_name_parts($long);
    if ($ps === [] || $pl === [] || count($ps) > count($pl)) {
        return false;
    }
    if (count($ps) >= 2 && $ps[count($ps) - 1] !== $pl[count($pl) - 1]) {
        return false;
    }
    $bag = $pl;
    foreach ($ps as $part) {
        $at = array_search($part, $bag, true);
        if ($at === false) {
            return false;
        }
        unset($bag[$at]);
    }

    return count($ps) >= 2 || count($pl) >= 2;
}

/**
 * @param array<string, mixed> $have
 * @param array<string, mixed> $incoming
 * @return array<string, mixed>
 */
function jeab_ordrar_person_fill_empty(array $have, array $incoming): array
{
    $havePnr = trim((string) ($have['personnummer'] ?? ''));
    $newPnr = trim((string) ($incoming['personnummer'] ?? ''));
    if ($havePnr === '' && $newPnr !== '') {
        $have['personnummer'] = $newPnr;
    }
    if (trim((string) ($have['name'] ?? '')) === '' && trim((string) ($incoming['name'] ?? '')) !== '') {
        $have['name'] = trim((string) $incoming['name']);
    }

    return $have;
}

/**
 * @param list<array<string, mixed>> $people
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_dedupe_people(array $people, int $limit = 8): array
{
    $out = [];
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $hit = null;
        foreach ($out as $i => $have) {
            if (jeab_ordrar_person_matches($have, $person)) {
                $hit = $i;
                break;
            }
        }
        if ($hit === null) {
            if (count($out) >= $limit) {
                continue;
            }
            $out[] = $person;
            continue;
        }
        $out[$hit] = jeab_ordrar_person_fill_empty($out[$hit], $person);
    }

    return $out;
}

/**
 * Ägare och medsökande, en rad per person. Första träffen behåller platsen
 * och ett tomt personnummer fylls från dubbletten.
 *
 * @param array<string, mixed> $row
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_applicant_people(array $row): array
{
    $lists = [
        jeab_ordrar_people_list($row['owners'] ?? [], 8),
        jeab_ordrar_people_list($row['medsokande'] ?? [], 8),
    ];
    $people = [];
    foreach ($lists as $list) {
        foreach ($list as $person) {
            $people[] = $person;
        }
    }

    return jeab_ordrar_dedupe_people($people, 8);
}

/**
 * Nyckel för dubblettkontroll i ägarlistan (personnummer, annars namn).
 *
 * @param array{name?:string,personnummer?:string,address?:string} $person
 */
function jeab_ordrar_owner_dedupe_key(array $person): string
{
    $digits = jeab_ordrar_identity_value(['personnummer' => $person['personnummer'] ?? ''], 'personnummer');
    if ($digits !== '') {
        return 'p:' . $digits;
    }
    return 'n:' . jeab_ordrar_name_fold((string) ($person['name'] ?? ''));
}

/**
 * Bygg owners + medsokande: huvudsökande först när hen finns i listan, övriga medsökande.
 *
 * @param list<array{name:string,personnummer:string,address:string,andel:string,lagfart:string}> $people
 * @return array{0:list<array{name:string,personnummer:string,address:string,andel:string,lagfart:string}>,1:list<array{name:string,personnummer:string,address:string,andel:string,lagfart:string}>}
 */
function jeab_ordrar_owners_split_huvud(array $people, string $huvudName, string $huvudPnr): array
{
    $ordered = [];
    $rest = [];
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $digits = jeab_ordrar_identity_value(['personnummer' => $person['personnummer'] ?? ''], 'personnummer');
        $isHuvud = ($huvudPnr !== '' && $digits === $huvudPnr)
            || ($huvudName !== '' && jeab_ordrar_same_person($huvudName, (string) ($person['name'] ?? '')));
        if ($isHuvud && $ordered === []) {
            $ordered[] = $person;
        } else {
            $rest[] = $person;
        }
    }
    $owners = array_slice(array_merge($ordered, $rest), 0, 8);
    $first = $owners[0] ?? null;
    $firstIsHuvud = is_array($first) && (
        ($huvudPnr !== '' && jeab_ordrar_identity_value(['personnummer' => $first['personnummer'] ?? ''], 'personnummer') === $huvudPnr)
        || ($huvudName !== '' && jeab_ordrar_same_person($huvudName, (string) ($first['name'] ?? '')))
    );
    $med = [];
    foreach ($owners as $i => $person) {
        if ($i === 0 && $firstIsHuvud) {
            continue;
        }
        if (count($med) >= 8) {
            break;
        }
        $med[] = $person;
    }
    return [$owners, $med];
}

/**
 * Höj sparad kalkyl.sokande när medsökande blivit fler.
 * Ett högre värde som redan står i kalkylen lämnas.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function jeab_ordrar_raise_stored_sokande(array $row): array
{
    $med = jeab_ordrar_people_list($row['medsokande'] ?? []);
    $floor = 1 + count($med);
    $kalkyl = $row['kalkyl'] ?? null;
    if (!is_array($kalkyl) || !array_key_exists('sokande', $kalkyl)) {
        return $row;
    }
    if ($kalkyl['sokande'] === '' || $kalkyl['sokande'] === null) {
        return $row;
    }
    $stored = (int) round((float) $kalkyl['sokande']);
    if ($stored < $floor) {
        $kalkyl['sokande'] = $floor;
        $row['kalkyl'] = $kalkyl;
    }
    return $row;
}

/**
 * Ägarlista från Lantmäteriet.
 *
 * Regel:
 * - Alla sparade poster automatiska (eller listan tom): ersätt med hela LM-listan
 *   (Ägare 1 = matchad huvudsökande, övriga medsökande). Adress från uppslaget
 *   när den har gata, annars orderns rättade adress.
 * - Någon post är handskriven / omarkerad med pnr: RADERA INTE blocket. Behåll
 *   varje befintligt kort orört (namn/pnr/adress skrivs inte över). Lägg till
 *   LM-ägare som saknas (medsökande / Ägare 2…). Nytillagda markeras lookup.
 *   Poster som inte matchar någon LM-ägare (äkta manuella tillägg) behålls.
 * - Vi hoppar aldrig över tillägget bara för att en matchande sparad ägare har
 *   pnr utan identity_source-markering (Håkansson-fallet efter rättad adress).
 *
 * @param array<string,mixed> $row
 * @param array<string,mixed> $suggest
 * @param list<string> $oldStreets
 * @return array<string,mixed>
 */
function jeab_ordrar_apply_lookup_owners(array $row, array $suggest, string $query, array $oldStreets = []): array
{
    if (!empty($suggest['lookup_empty'])) {
        return $row;
    }
    $lm = jeab_ordrar_people_list(is_array($suggest['owners'] ?? null) ? $suggest['owners'] : [], 8);
    if ($lm === []) {
        return $row;
    }
    $known = $oldStreets;
    foreach (jeab_ordrar_old_streets($row) as $sig) {
        if (!in_array($sig, $known, true)) {
            $known[] = $sig;
        }
    }
    $existing = array_merge(jeab_ordrar_people_list($row['owners'] ?? [], 8), jeab_ordrar_people_list($row['medsokande'] ?? [], 8));
    $replaceOwners = !empty($GLOBALS['_JEAB_LM_OWNER_REPLACE']);
    $allAuto = true;
    if (!$replaceOwners) {
        foreach ($existing as $person) {
            if (!jeab_ordrar_owner_entry_auto($row, $person, $known)) {
                $allAuto = false;
                break;
            }
        }
    }
    $orderAddr = trim((string) ($row['huvudsokande_address'] ?? ''));
    if ($orderAddr === '') {
        $orderAddr = trim((string) ($row['address'] ?? ''));
    }
    $huvudName = trim((string) ($row['huvudsokande'] ?? ''));
    $huvudPnr = jeab_ordrar_identity_value($row, 'personnummer');

    $lmReady = [];
    $seenLm = [];
    foreach ($lm as $person) {
        $key = jeab_ordrar_owner_dedupe_key($person);
        if ($key === 'n:' || isset($seenLm[$key])) {
            continue;
        }
        $seenLm[$key] = true;
        if (!jeab_ordrar_looks_like_street(trim((string) ($person['address'] ?? '')))) {
            $keptAddr = '';
            foreach ($existing as $saved) {
                if (!jeab_ordrar_owners_same_person($saved, $person)) {
                    continue;
                }
                $candidate = trim((string) ($saved['address'] ?? ''));
                if (jeab_ordrar_looks_like_street($candidate)) {
                    $keptAddr = $candidate;
                    break;
                }
            }
            $person['address'] = $keptAddr !== '' ? $keptAddr : $orderAddr;
        }
        $lmReady[] = $person;
    }
    if ($lmReady === []) {
        return $row;
    }

    if ($allAuto) {
        $owners = [];
        foreach ($lmReady as $person) {
            $digits = jeab_ordrar_identity_value(['personnummer' => $person['personnummer']], 'personnummer');
            $isHuvud = ($huvudPnr !== '' && $digits === $huvudPnr)
                || ($huvudPnr === '' && $huvudName !== '' && jeab_ordrar_same_person($huvudName, (string) $person['name']));
            if ($isHuvud) {
                array_unshift($owners, $person);
            } else {
                $owners[] = $person;
            }
        }
        [$owners, $med] = jeab_ordrar_owners_split_huvud($owners, $huvudName, $huvudPnr);
        $samePeople = jeab_ordrar_people_list($row['owners'] ?? [], 8) === jeab_ordrar_people_list($owners, 8)
            && jeab_ordrar_people_list($row['medsokande'] ?? [], 8) === jeab_ordrar_people_list($med, 8);
        if ($samePeople) {
            return $row;
        }
        $sigs = [];
        foreach (array_merge($owners, $med) as $person) {
            $sig = jeab_ordrar_owner_sig($person);
            if (!in_array($sig, $sigs, true)) {
                $sigs[] = $sig;
            }
        }
        $row['owners'] = $owners;
        $row['medsokande'] = $med;
        $marks = is_array($row['identity_source'] ?? null) ? $row['identity_source'] : [];
        $marks['owners'] = ['src' => 'lookup', 'entries' => $sigs, 'address' => $query, 'at' => jeab_now()];
        $row['identity_source'] = $marks;
        if ($replaceOwners && isset($owners[0]) && is_array($owners[0])) {
            $name = trim((string) ($owners[0]['name'] ?? ''));
            if ($name !== '') {
                $row['huvudsokande'] = $name;
            }
            $pnr = jeab_ordrar_format_pnr((string) ($owners[0]['personnummer'] ?? ''));
            if ($pnr !== '') {
                $row['personnummer'] = $pnr;
            }
            $addr = trim((string) ($owners[0]['address'] ?? ''));
            if ($addr !== '') {
                $row['huvudsokande_address'] = $addr;
            }
        }
        return jeab_ordrar_raise_stored_sokande($row);
    }

    // Behåll befintliga kort orörda. Lägg bara till saknade LM-ägare.
    $kept = [];
    $seenKept = [];
    foreach ($existing as $person) {
        $key = jeab_ordrar_owner_dedupe_key($person);
        if ($key === 'n:' && trim((string) ($person['name'] ?? '')) === '') {
            continue;
        }
        if (isset($seenKept[$key])) {
            continue;
        }
        $seenKept[$key] = true;
        $kept[] = $person;
    }
    $added = [];
    $filledPnr = false;
    foreach ($lmReady as $lmPerson) {
        $matched = false;
        foreach ($kept as $ki => $saved) {
            if (jeab_ordrar_owners_same_person($saved, $lmPerson)) {
                $matched = true;
                $lmPnr = jeab_ordrar_format_pnr((string) ($lmPerson['personnummer'] ?? ''));
                if ($lmPnr !== '' && trim((string) ($saved['personnummer'] ?? '')) === '') {
                    $kept[$ki]['personnummer'] = $lmPnr;
                    $filledPnr = true;
                }
                break;
            }
        }
        if ($matched) {
            continue;
        }
        $key = jeab_ordrar_owner_dedupe_key($lmPerson);
        if (isset($seenKept[$key])) {
            continue;
        }
        $seenKept[$key] = true;
        $kept[] = $lmPerson;
        $added[] = $lmPerson;
    }
    if ($added === [] && !$filledPnr) {
        // Inget nytt från LM — rör inte sparade kort eller identity_source.
        return $row;
    }
    [$owners, $med] = jeab_ordrar_owners_split_huvud($kept, $huvudName, $huvudPnr);
    $prevMark = is_array($row['identity_source']['owners'] ?? null) ? $row['identity_source']['owners'] : null;
    $prevEntries = is_array($prevMark['entries'] ?? null) ? $prevMark['entries'] : [];
    $finalPeople = array_merge($owners, $med);
    $sigs = [];
    foreach ($prevEntries as $sig) {
        foreach ($finalPeople as $person) {
            if (jeab_ordrar_owner_sig($person) === $sig && !in_array($sig, $sigs, true)) {
                $sigs[] = $sig;
                break;
            }
        }
    }
    foreach ($added as $person) {
        $sig = jeab_ordrar_owner_sig($person);
        if (!in_array($sig, $sigs, true)) {
            $sigs[] = $sig;
        }
    }
    $row['owners'] = $owners;
    $row['medsokande'] = $med;
    $marks = is_array($row['identity_source'] ?? null) ? $row['identity_source'] : [];
    $marks['owners'] = ['src' => 'lookup', 'entries' => $sigs, 'address' => $query, 'at' => jeab_now()];
    $row['identity_source'] = $marks;
    return jeab_ordrar_raise_stored_sokande($row);
}

/**
 * Uppdatera: ägarlistan, fastighet och personnummer från uppslaget när de är tomma eller automatiska.
 * Samma ursprungsregler som vid adressändring. Ingen bokning, mejl, sms eller Fortnox.
 */
function jeab_ordrar_sync_lookup_owners(array $row, ?array $reminder = null, bool $save = true): array
{
    if (!jeab_ordrar_external_live()) {
        return $row;
    }
    try {
        $suggest = jeab_ordrar_confirm_suggest($row, $reminder);
    } catch (Throwable $e) {
        if (function_exists('jeab_rot_lookup_log')) {
            jeab_rot_lookup_log('fel vid uppslag (Uppdatera)', get_class($e));
        }
        return $row;
    }
    if (!empty($suggest['lookup_empty'])) {
        return $row;
    }
    $query = jeab_ordrar_lookup_query($reminder, $row);
    $filled = jeab_ordrar_apply_lookup_identity($row, $suggest, $query);
    $filled = jeab_ordrar_apply_lookup_owners($filled, $suggest, $query, jeab_ordrar_old_streets($row, $reminder));
    [$filled] = jeab_ordrar_apply_lookup_postnummer($filled, $suggest);
    $postFilled = jeab_ordrar_postnummer_digits((string) ($filled['postnummer'] ?? '')) !== ''
        && jeab_ordrar_postnummer_digits((string) ($row['postnummer'] ?? $row['postnr'] ?? '')) === '';
    $soleStrips = function_exists('jeab_contacts_sole_order_should_strip') && jeab_contacts_sole_order_should_strip($row);
    if ($postFilled && !$soleStrips) {
        $filled = jeab_ordrar_mark_identity_source($filled, 'postnummer', 'lookup', $query);
    }
    $probe = $filled;
    if ($soleStrips && function_exists('jeab_contacts_sole_order_duplicate_keys')) {
        foreach (jeab_contacts_sole_order_duplicate_keys() as $key) {
            if (array_key_exists($key, $row)) {
                $probe[$key] = $row[$key];
            } else {
                unset($probe[$key]);
            }
        }
    }
    if (json_encode($probe) === json_encode($row) || json_encode($filled) === json_encode($row)) {
        return $row;
    }
    if (!$save || (int) ($filled['reminder_id'] ?? 0) <= 0) {
        return $filled;
    }
    $filled['updated_at'] = jeab_now();
    $saved = jeab_purchase_put($filled);
    if (function_exists('jeab_rot_sole_keep_owners')) {
        jeab_rot_sole_keep_owners($saved);
    } elseif (function_exists('jeab_contacts_sole_divert_property')) {
        try {
            jeab_contacts_sole_divert_property($saved);
        } catch (Throwable) {
        }
    }
    jeab_ordrar_sync_lm_contact($saved);

    return $saved;
}

/** Bekräfta: signaturer för ägarkort som kommer från uppslaget (dolt i formuläret). */
function jeab_ordrar_owner_forslag(array $open, ?array $suggest, array $cards): array
{
    $from = [];
    if (is_array($suggest) && empty($suggest['lookup_empty'])) {
        foreach (jeab_ordrar_people_list(is_array($suggest['owners'] ?? null) ? $suggest['owners'] : [], 8) as $person) {
            $from[] = jeab_ordrar_owner_sig($person);
        }
    }
    $mark = is_array($open['identity_source']['owners'] ?? null) ? $open['identity_source']['owners'] : [];
    foreach (is_array($mark['entries'] ?? null) ? $mark['entries'] : [] as $sig) {
        $from[] = (string) $sig;
    }
    $out = [];
    foreach ($cards as $card) {
        $sig = jeab_ordrar_owner_sig($card);
        if (in_array($sig, $from, true) && !in_array($sig, $out, true)) {
            $out[] = $sig;
        }
    }
    return $out;
}

/**
 * Adress eller postnummer ändrat och sparat: nytt Lantmäteriet-uppslag på den nya adressen.
 * Tomma fält och fält som fyllts automatiskt (från gamla adressen) fylls/ersätts.
 * Handskrivna värden, och gamla värden utan markering, lämnas orörda.
 * Bara uppslag: ingen bokning, mejl, sms eller Fortnox. Oförändrad adress gör inget uppslag.
 */
function jeab_ordrar_relookup_after_address_change(array $before, array $row, ?array $reminder = null): array
{
    $newKey = jeab_ordrar_address_key($row);
    if ($newKey === '' || $newKey === jeab_ordrar_address_key($before)) {
        return $row;
    }
    $row['address_edited_at'] = jeab_now();
    if (!jeab_ordrar_external_live()) {
        return $row;
    }
    $query = jeab_ordrar_lookup_query(null, $row);
    if ($query === '') {
        return $row;
    }
    if (!is_array($reminder)) {
        try {
            $reminder = jeab_ordrar_reminder_for_row($row);
        } catch (Throwable) {
            $reminder = null;
        }
    }
    try {
        $suggest = jeab_ordrar_confirm_suggest($row, $reminder, $query);
    } catch (Throwable $e) {
        if (function_exists('jeab_rot_lookup_log')) {
            jeab_rot_lookup_log('fel vid nytt uppslag efter adressändring', get_class($e));
        }
        $scrubbed = jeab_ordrar_scrub_pnr(trim($e->getMessage()));
        $row['lm_lookup_error'] = is_string($scrubbed) && $scrubbed !== '' ? $scrubbed : 'Lantmäteriet-uppslaget misslyckades.';
        return $row;
    }
    $row['lm_lookup_error'] = '';
    $replaceable = static fn(array $r, string $f): bool => jeab_ordrar_identity_source($r, $f) !== 'manual';
    $oldStreets = jeab_ordrar_old_streets($before, $reminder);
    $row['address_previous'] = trim((string) ($before['address'] ?? $before['huvudsokande_address'] ?? ''));
    $row = jeab_ordrar_apply_lookup_identity($row, $suggest, $query);
    $row = jeab_ordrar_apply_lookup_owners($row, $suggest, $query, $oldStreets);
    $zip = jeab_ordrar_postnummer_digits((string) ($suggest['postnummer'] ?? ''));
    if ($zip !== '' && $replaceable($row, 'postnummer') && $zip !== jeab_ordrar_identity_value($row, 'postnummer')) {
        $cleared = $row;
        $cleared['postnummer'] = '';
        $cleared['postnr'] = '';
        [$zipped, $changed] = jeab_ordrar_assign_postnummer($cleared, $zip, trim((string) ($suggest['ort'] ?? '')), true);
        if ($changed) {
            $row = jeab_ordrar_mark_identity_source($zipped, 'postnummer', 'lookup', $query);
        }
    } elseif ($zip !== '' && $zip === jeab_ordrar_identity_value($row, 'postnummer') && jeab_ordrar_identity_source($row, 'postnummer') === 'lookup') {
        $row = jeab_ordrar_mark_identity_source($row, 'postnummer', 'lookup', $query);
    }
    if (jeab_ordrar_identity_missing($row) !== []) {
        $row['identity_filled_at'] = jeab_now();
    } else {
        $row['identity_filled_at'] = null;
    }
    return $row;
}

/**
 * Personnummer och fastighetsbeteckning för Objekt. Sparat värde vinner alltid.
 * Tomt fält får samma förslag som Bekräfta (Lantmäteriet-uppslaget).
 * Uppslaget utan träff (t.ex. bostadsrätt) lämnar fältet tomt.
 *
 * @return array{personnummer:string,fastighetsbeteckning:string,personnummer_forslag?:string,fastighetsbeteckning_forslag?:string}
 */
function jeab_ordrar_status_identity(array $open, int $openId = 0): array
{
    // Bara vid misslyckad Objekt-sparning i samma anrop. Ligger aldrig på ordern.
    $posted = is_array($GLOBALS['_JEAB_OBJEKT_POSTED'] ?? null) ? $GLOBALS['_JEAB_OBJEKT_POSTED'] : null;
    if ($posted !== null) {
        return [
            'personnummer' => trim((string) ($posted['personnummer'] ?? '')),
            'fastighetsbeteckning' => trim((string) ($posted['fastighetsbeteckning'] ?? '')),
        ];
    }
    $pnr = trim((string) ($open['personnummer'] ?? ''));
    $fast = trim((string) ($open['fastighetsbeteckning'] ?? ''));
    if ($pnr !== '' && $fast !== '') {
        return ['personnummer' => $pnr, 'fastighetsbeteckning' => $fast, 'personnummer_forslag' => '', 'fastighetsbeteckning_forslag' => ''];
    }
    $savedPnr = $pnr;
    $savedFast = $fast;
    try {
        $reminder = jeab_ordrar_reminder_for_row($open, $openId);
        $suggest = jeab_ordrar_confirm_suggest($open, is_array($reminder) ? $reminder : null);
    } catch (Throwable) {
        $suggest = [];
    }
    if ($pnr === '') {
        $pnr = trim((string) ($suggest['personnummer'] ?? ''));
    }
    if ($fast === '') {
        $fast = trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
    }
    return [
        'personnummer' => $pnr,
        'fastighetsbeteckning' => $fast,
        // Visat förslag från uppslaget. Sparas det oförändrat räknas det som automatiskt, inte handskrivet.
        'personnummer_forslag' => $savedPnr === '' ? $pnr : '',
        'fastighetsbeteckning_forslag' => $savedFast === '' ? $fast : '',
    ];
}

/**
 * Lantmäteriet-träffen och ordersparningen skriver personnummer på den
 * matchande ägarplatsen (1 eller 2) och fastighetsbeteckning på kontakten.
 * Ett annat ifyllt personnummer skrivs inte över. Felloggen får ingen sifferserie.
 */
function jeab_ordrar_sync_lm_contact(array $order): bool
{
    if (!empty($GLOBALS['_JEAB_LM_CONTACT_SYNC'])) {
        $GLOBALS['_JEAB_LM_CONTACT_SYNC_REASON'] = 'synken pågår redan';

        return false;
    }
    if (!function_exists('jeab_kontakt_get') || !function_exists('jeab_kontakt_save_input')) {
        $GLOBALS['_JEAB_LM_CONTACT_SYNC_REASON'] = 'kontakten kunde inte läsas';

        return false;
    }
    $contactId = trim((string) ($order['contact_id'] ?? ''));
    if ($contactId === '') {
        $GLOBALS['_JEAB_LM_CONTACT_SYNC_REASON'] = 'kontakten saknas';

        return false;
    }
    $contact = jeab_kontakt_get($contactId);
    if (!is_array($contact)) {
        $GLOBALS['_JEAB_LM_CONTACT_SYNC_REASON'] = 'kontakten kunde inte läsas';

        return false;
    }
    $GLOBALS['_JEAB_LM_CONTACT_SYNC'] = 1;
    try {
        return jeab_ordrar_sync_lm_contact_apply($order, $contactId, $contact);
    } catch (Throwable) {
        jeab_ordrar_sync_lm_log('Numret kunde inte skrivas.');
        $GLOBALS['_JEAB_LM_CONTACT_SYNC_REASON'] = 'numret kunde inte sparas på kontakten';

        return false;
    } finally {
        unset($GLOBALS['_JEAB_LM_CONTACT_SYNC']);
    }
}

function jeab_ordrar_sync_lm_log(string $message): void
{
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    jeab_log_app_error('ordrar.lm_kontakt', $message, [
        'skip_request' => true,
        'no_webhook' => true,
    ]);
}

function jeab_ordrar_sync_lm_pnr_empty(string $raw): bool
{
    $raw = trim($raw);
    if ($raw === '') {
        return true;
    }
    if (str_contains($raw, '*') || str_contains($raw, '•')) {
        return false;
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    return strlen($digits) < 10;
}

function jeab_ordrar_sync_lm_pnr_key(string $raw): string
{
    if (jeab_ordrar_sync_lm_pnr_empty($raw)) {
        return '';
    }
    if (function_exists('jeab_norm_pnr')) {
        $norm = jeab_norm_pnr($raw);
        if ($norm !== '') {
            return $norm;
        }
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    return strlen($digits) >= 10 ? $digits : '';
}

/**
 * @param array<string, mixed> $order
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_sync_lm_people(array $order): array
{
    $people = function_exists('jeab_ordrar_people_list')
        ? jeab_ordrar_people_list($order['owners'] ?? [], 2)
        : [];
    $med = function_exists('jeab_ordrar_people_list')
        ? jeab_ordrar_people_list($order['medsokande'] ?? [], 2)
        : [];
    foreach ($med as $person) {
        if (count($people) >= 2) {
            break;
        }
        $seen = false;
        foreach ($people as $have) {
            if (jeab_ordrar_person_matches($person, $have)) {
                $seen = true;
                break;
            }
        }
        if (!$seen) {
            $people[] = $person;
        }
    }
    $huvudName = trim((string) ($order['huvudsokande'] ?? ''));
    $huvudPnr = trim((string) ($order['personnummer'] ?? ''));
    if ($huvudPnr !== '') {
        $filled = false;
        foreach ($people as $i => $person) {
            if (!jeab_ordrar_sync_lm_pnr_empty((string) ($person['personnummer'] ?? ''))) {
                continue;
            }
            $name = trim((string) ($person['name'] ?? ''));
            if ($huvudName !== '' && jeab_ordrar_same_person($name, $huvudName)) {
                $people[$i]['personnummer'] = $huvudPnr;
                $filled = true;
                break;
            }
        }
        if (!$filled && $people === [] && $huvudName !== '') {
            $people[] = ['name' => $huvudName, 'personnummer' => $huvudPnr];
        }
    }

    return array_slice($people, 0, 2);
}

/**
 * @param array<string, mixed> $order
 * @param array<string, mixed> $contact
 */
function jeab_ordrar_sync_lm_contact_apply(array $order, string $contactId, array $contact): bool
{
    $rawOwners = [];
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $rawOwners[] = $owner;
        }
    }
    foreach ($rawOwners as $i => $owner) {
        if ($i < 2) {
            continue;
        }
        if (trim((string) ($owner['name'] ?? '')) !== '') {
            $GLOBALS['_JEAB_LM_CONTACT_SYNC_REASON'] = 'fler än två ägare';

            return false;
        }
    }
    $slots = [
        is_array($rawOwners[0] ?? null) ? $rawOwners[0] : null,
        is_array($rawOwners[1] ?? null) ? $rawOwners[1] : null,
    ];
    $people = jeab_ordrar_sync_lm_people($order);
    $assign = [0 => null, 1 => null];
    $used = [];
    $unmatched = [];
    foreach ($people as $person) {
        $name = trim((string) ($person['name'] ?? ''));
        $hit = null;
        foreach ([0, 1] as $slot) {
            if (isset($used[$slot]) || !is_array($slots[$slot])) {
                continue;
            }
            $have = trim((string) ($slots[$slot]['name'] ?? ''));
            if ($name !== '' && $have !== '' && jeab_ordrar_person_matches(
                ['name' => $name, 'personnummer' => (string) ($person['personnummer'] ?? '')],
                ['name' => $have, 'personnummer' => (string) ($slots[$slot]['personnummer'] ?? '')]
            )) {
                $hit = $slot;
                break;
            }
        }
        if ($hit === null) {
            $unmatched[] = $person;
            continue;
        }
        $used[$hit] = true;
        $assign[$hit] = $person;
    }
    $placed = [];
    foreach ($assign as $person) {
        if (!is_array($person)) {
            continue;
        }
        foreach ($people as $pi => $candidate) {
            if (isset($placed[$pi]) || !is_array($candidate)) {
                continue;
            }
            if (jeab_ordrar_person_matches($person, $candidate)) {
                $placed[$pi] = true;
            }
        }
    }
    foreach ([0, 1] as $slot) {
        if ($assign[$slot] !== null || !isset($people[$slot]) || isset($placed[$slot]) || !is_array($people[$slot])) {
            continue;
        }
        $existing = is_array($slots[$slot]) ? $slots[$slot] : [];
        if (!jeab_ordrar_sync_lm_pnr_empty((string) ($existing['personnummer'] ?? ''))) {
            continue;
        }
        $assign[$slot] = $people[$slot];
        $used[$slot] = true;
        $placed[$slot] = true;
    }
    foreach ($unmatched as $person) {
        $already = false;
        foreach ($assign as $have) {
            if (is_array($have) && jeab_ordrar_person_matches($person, $have)) {
                $already = true;
                break;
            }
        }
        if ($already) {
            continue;
        }
        foreach ([0, 1] as $slot) {
            if (isset($used[$slot])) {
                continue;
            }
            $have = is_array($slots[$slot]) ? trim((string) ($slots[$slot]['name'] ?? '')) : '';
            if ($have !== '') {
                continue;
            }
            if ($slot === 1 && count($people) < 2) {
                continue;
            }
            $used[$slot] = true;
            $assign[$slot] = $person;
            break;
        }
    }
    $postName = ['', ''];
    $postPnr = ['', ''];
    $changed = false;
    foreach ([0, 1] as $slot) {
        $existing = is_array($slots[$slot]) ? $slots[$slot] : [];
        $haveName = trim((string) ($existing['name'] ?? ''));
        $havePnr = trim((string) ($existing['personnummer'] ?? ''));
        $person = $assign[$slot];
        if (!is_array($person)) {
            $postName[$slot] = $haveName;
            $postPnr[$slot] = jeab_ordrar_sync_lm_pnr_empty($havePnr) ? '' : '******';
            continue;
        }
        $name = trim((string) ($person['name'] ?? ''));
        if ($name === '') {
            $name = $haveName;
        }
        $postName[$slot] = $haveName !== '' ? $haveName : $name;
        if ($haveName === '' && $name !== '') {
            $changed = true;
        }
        $fetched = trim((string) ($person['personnummer'] ?? ''));
        if (jeab_ordrar_sync_lm_pnr_empty($fetched)) {
            $postPnr[$slot] = jeab_ordrar_sync_lm_pnr_empty($havePnr) ? '' : '******';
            continue;
        }
        $haveKey = jeab_ordrar_sync_lm_pnr_key($havePnr);
        $newKey = jeab_ordrar_sync_lm_pnr_key($fetched);
        if ($haveKey !== '' && $newKey !== '' && $haveKey === $newKey) {
            $postPnr[$slot] = '******';
            continue;
        }
        if (!jeab_ordrar_sync_lm_pnr_empty($havePnr)) {
            jeab_ordrar_sync_lm_log('Ägarplatsen har ett annat personnummer. Inget skrevs över.');
            $postPnr[$slot] = '******';
            continue;
        }
        if (!function_exists('jeab_kontakt_pnr_valid') || !jeab_kontakt_pnr_valid($fetched)) {
            jeab_ordrar_sync_lm_log('Personnumret har fel format. Inget skrevs.');
            $postPnr[$slot] = '';
            continue;
        }
        $postPnr[$slot] = $fetched;
        $changed = true;
    }
    foreach ([0, 1] as $slot) {
        if ($postName[$slot] === '') {
            $postPnr[$slot] = '';
        }
    }
    $fast = trim((string) ($order['fastighetsbeteckning'] ?? ''));
    if (function_exists('jeab_ordrar_format_beteckning')) {
        $fast = jeab_ordrar_format_beteckning($fast);
    }
    $haveFast = trim((string) ($contact['fastighetsbeteckning'] ?? ''));
    $haveFastNorm = function_exists('jeab_ordrar_format_beteckning')
        ? jeab_ordrar_format_beteckning($haveFast)
        : $haveFast;
    $nextFast = $haveFast;
    if ($fast !== '' && $fast !== $haveFastNorm) {
        $nextFast = $fast;
        $changed = true;
    }
    $street = trim((string) ($contact['street'] ?? ''));
    if ($street === '') {
        $street = trim((string) ($order['gata'] ?? ''));
        if ($street === '') {
            $addr = trim((string) ($order['address'] ?? $order['huvudsokande_address'] ?? ''));
            $street = trim((string) explode(',', $addr)[0]);
        }
        if ($street !== '') {
            $changed = true;
        }
    }
    if (!$changed || $street === '' || ($postName[0] === '' && $postName[1] === '')) {
        $GLOBALS['_JEAB_LM_CONTACT_SYNC_REASON'] = $street === '' ? 'gatan saknas' : 'ingen tom plats att fylla';

        return false;
    }
    $postal = trim((string) ($contact['postal_code'] ?? ''));
    if ($postal === '') {
        $postal = trim((string) ($order['postnummer'] ?? $order['postnr'] ?? ''));
    }
    $city = trim((string) ($contact['city'] ?? ''));
    if ($city === '') {
        $city = trim((string) ($order['ort'] ?? ''));
    }
    $contactSlot = '1';
    foreach ([0, 1] as $slot) {
        if (is_array($slots[$slot]) && !empty($slots[$slot]['contact'])) {
            $contactSlot = (string) ($slot + 1);
        }
    }
    $source = is_array($slots[(int) $contactSlot - 1] ?? null) ? $slots[(int) $contactSlot - 1] : [];
    $email = trim((string) ($source['email'] ?? ''));
    $phone = trim((string) ($source['phone'] ?? ''));
    if ($email === '' && function_exists('jeab_kontakt_row_email')) {
        $email = jeab_kontakt_row_email($contact);
    }
    if ($phone === '' && function_exists('jeab_kontakt_row_phone')) {
        $phone = jeab_kontakt_row_phone($contact);
    }
    $input = [
        'id' => $contactId,
        'street' => $street,
        'postal_code' => $postal,
        'city' => $city,
        'fastighetsbeteckning' => $nextFast,
        'email' => $email,
        'phone' => $phone,
        'contact_person' => $contactSlot,
        'owner_name_1' => $postName[0],
        'owner_pnr_1' => $postPnr[0],
    ];
    if ($postName[1] !== '' || $postPnr[1] !== '') {
        $input['owner_name_2'] = $postName[1];
        $input['owner_pnr_2'] = $postPnr[1];
    }
    if (isset($contact['company'])) {
        $input['company'] = $contact['company'];
    }
    $input['owner_stamp'] = 'lantmateriet';
    $saved = jeab_kontakt_save_input($input);
    $savedId = is_array($saved) ? trim((string) ($saved['id'] ?? '')) : '';
    if ($savedId !== $contactId) {
        jeab_ordrar_sync_lm_log('Numret kunde inte skrivas.');
        $GLOBALS['_JEAB_LM_CONTACT_SYNC_REASON'] = 'numret kunde inte sparas på kontakten';

        return false;
    }
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($contactId);
    }

    return true;
}

/** Hook-kontexten laddar kontakten och ångringshjälparna själv. */
function jeab_ordrar_sync_owner_pnr_load(): void
{
    foreach (['kontakter.php', 'contacts_sole.php', 'fastighet_refresh.php', 'ordrar_fortnox.php'] as $name) {
        $file = __DIR__ . '/' . $name;
        if (is_file($file)) {
            require_once $file;
        }
    }
}

/**
 * Vad ordrar.sync_owner_pnr skulle skriva. Bara plats och ja/nej.
 * Inget personnummer.
 *
 * @param array<string, mixed> $order
 * @return array{would:int,contact_id:string,slots:list<array{slot:int,had:int,would:int}>}
 */
function jeab_ordrar_sync_owner_pnr_preview(array $order): array
{
    $contactId = trim((string) ($order['contact_id'] ?? ''));
    $empty = ['would' => 0, 'contact_id' => $contactId, 'slots' => []];
    if ($contactId === '' || !function_exists('jeab_kontakt_get')) {
        return $empty;
    }
    $contact = jeab_kontakt_get($contactId);
    if (!is_array($contact)) {
        $empty['contact_id'] = '';

        return $empty;
    }
    $rawOwners = [];
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $rawOwners[] = $owner;
        }
    }
    $people = jeab_ordrar_sync_lm_people($order);
    $slots = [];
    $would = 0;
    foreach ([0, 1] as $slot) {
        $existing = is_array($rawOwners[$slot] ?? null) ? $rawOwners[$slot] : [];
        $have = !jeab_ordrar_sync_lm_pnr_empty((string) ($existing['personnummer'] ?? ''));
        $person = is_array($people[$slot] ?? null) ? $people[$slot] : null;
        $fetched = is_array($person) ? trim((string) ($person['personnummer'] ?? '')) : '';
        $can = !$have && $fetched !== '' && (!function_exists('jeab_kontakt_pnr_valid') || jeab_kontakt_pnr_valid($fetched));
        if ($can) {
            $would = 1;
        }
        $slots[] = ['slot' => $slot + 1, 'had' => $have ? 1 : 0, 'would' => $can ? 1 : 0];
    }

    return ['would' => $would, 'contact_id' => $contactId, 'slots' => $slots];
}

/**
 * Skriver huvudägarens och andra ägarens tomma personnummer på kontaktens plats 1 och 2.
 * Ångring sparas först. Inget personnummer lämnar svaret.
 *
 * @param array<string, mixed> $order
 * @return array<string, mixed>
 */
function jeab_ordrar_sync_owner_pnr_commit(array $order): array
{
    jeab_ordrar_sync_owner_pnr_load();
    $preview = jeab_ordrar_sync_owner_pnr_preview($order);
    $base = [
        'written' => 0,
        'batch_id' => '',
        'would' => (int) ($preview['would'] ?? 0),
        'contact_id' => (string) ($preview['contact_id'] ?? ''),
        'slots' => $preview['slots'] ?? [],
    ];
    if ((int) ($preview['would'] ?? 0) !== 1) {
        return $base;
    }
    $contactId = (string) $preview['contact_id'];
    $contact = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($contactId) : null;
    if (!function_exists('jeab_kontakt_get') || !is_array($contact)) {
        $base['error'] = 'Kontakten kunde inte läsas.';
        $base['reason'] = 'contact';

        return $base;
    }
    if (!function_exists('jeab_contacts_sole_undo_add') || !function_exists('jeab_contacts_sole_batch')) {
        $base['error'] = 'Ångringshjälpen saknas.';
        $base['reason'] = 'undo';

        return $base;
    }
    $beforeOwners = [];
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if (jeab_ordrar_sync_lm_pnr_empty((string) ($owner['personnummer'] ?? ''))) {
            $owner['pnr_clear'] = 1;
        }
        $beforeOwners[] = $owner;
    }
    $batch = jeab_contacts_sole_batch();
    $logged = jeab_contacts_sole_undo_add($batch, 1, 'owner', 'contact', $contactId, [
        'owners' => $beforeOwners,
        'updated_at' => trim((string) ($contact['updated_at'] ?? '')),
    ], [
        'owners' => $beforeOwners,
        'result' => 'wrote',
    ]);
    if (!$logged) {
        $base['error'] = 'Kunde inte spara ångringen.';

        return $base;
    }
    unset($GLOBALS['_JEAB_LM_CONTACT_SYNC_REASON']);
    $ok = jeab_ordrar_sync_lm_contact($order);
    if (!$ok) {
        if (function_exists('jeab_contacts_sole_undo_mark')) {
            jeab_contacts_sole_undo_mark($batch);
        }
        $why = trim((string) ($GLOBALS['_JEAB_LM_CONTACT_SYNC_REASON'] ?? ''));
        $base['error'] = $why !== '' ? $why : 'Numret kunde inte sparas på kontakten.';
        $base['reason'] = 'write';
        $base['batch_id'] = $batch;

        return $base;
    }
    $base['written'] = 1;
    $base['batch_id'] = $batch;

    return $base;
}

/**
 * ordrar.sync_owner_pnr. dry=1 är standard och skriver inget.
 * dry=0 kräver confirm=SAM. Ångring sparas först.
 * Svaret har plats och ja/nej, aldrig personnummer. Inget mejl.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_sync_owner_pnr_run(JeabStore $store, array $input = []): array
{
    jeab_ordrar_sync_owner_pnr_load();
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = function_exists('jeab_ordrar_backfill_wants_dry') ? jeab_ordrar_backfill_wants_dry($input) : true;
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    $action = 'ordrar.sync_owner_pnr';
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange order_id', 'action' => $action, 'dry' => $dry ? 1 : 0, 'written' => 0, 'mail' => 0, 'status' => 422];
    }
    $row = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'written' => 0, 'mail' => 0, 'status' => 404];
    }
    $preview = jeab_ordrar_sync_owner_pnr_preview($row);
    $base = [
        'ok' => true,
        'action' => $action,
        'dry' => $dry ? 1 : 0,
        'written' => 0,
        'mail' => 0,
        'order_id' => $id,
        'contact_id' => (string) ($preview['contact_id'] ?? ''),
        'would' => (int) ($preview['would'] ?? 0),
        'slots' => $preview['slots'] ?? [],
        'status' => 200,
    ];
    if ($dry || (int) ($preview['would'] ?? 0) !== 1) {
        return jeab_ordrar_scrub_pnr($base);
    }
    if (trim((string) ($input['confirm'] ?? '')) !== 'SAM') {
        return [
            'ok' => false,
            'error' => 'dry=0 kräver confirm=SAM',
            'action' => $action,
            'dry' => 0,
            'order_id' => $id,
            'written' => 0,
            'mail' => 0,
            'status' => 422,
        ];
    }
    $synced = jeab_ordrar_sync_owner_pnr_commit($row);
    if ((int) ($synced['written'] ?? 0) !== 1) {
        $fail = jeab_ordrar_scrub_pnr([
            'ok' => false,
            'error' => (string) ($synced['error'] ?? 'Numret kunde inte sparas på kontakten.'),
            'reason' => (string) ($synced['reason'] ?? ''),
            'action' => $action,
            'dry' => 0,
            'order_id' => $id,
            'written' => 0,
            'mail' => 0,
            'batch_id' => (string) ($synced['batch_id'] ?? ''),
            'status' => 503,
        ]);

        return is_array($fail) ? $fail : ['ok' => false, 'error' => 'Numret kunde inte skrivas.', 'status' => 503];
    }
    $base['dry'] = 0;
    $base['written'] = 1;
    $base['batch_id'] = (string) ($synced['batch_id'] ?? '');

    $clean = jeab_ordrar_scrub_pnr($base);

    return is_array($clean) ? $clean : $base;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_sync_owner_pnr_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.sync_owner_pnr', 'status' => 401];
    }

    return jeab_ordrar_sync_owner_pnr_run($store, $input);
}

function jeab_ordrar_save_objekt(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $before = $row;
    $beforeKey = jeab_ordrar_address_key($before);
    // Personnummer hör till ägarkortet. Objekt sparar inte ett eget personnummer.
    $addressTouched = array_key_exists('objekt_adress', $input)
        || array_key_exists('objekt_postort', $input)
        || array_key_exists('objekt_postnummer', $input);
    if ($addressTouched) {
        $street = trim((string) ($input['objekt_adress'] ?? ''));
        $place = trim((string) ($input['objekt_postort'] ?? ''));
        $parsed = jeab_ordrar_parse_postort($place);
        $zipDigits = '';
        $postFmt = '';
        if (array_key_exists('objekt_postnummer', $input)) {
            $zipDigits = jeab_ordrar_postnummer_digits((string) $input['objekt_postnummer']);
            $postFmt = $zipDigits !== '' ? jeab_ordrar_format_postnummer($zipDigits) : '';
        } else {
            $zipDigits = jeab_ordrar_postnummer_digits($parsed['post']);
            $postFmt = $parsed['post'];
        }
        $ort = $parsed['ort'];
        $placeComposed = trim($postFmt . ($postFmt !== '' && $ort !== '' ? ' ' . $ort : ''));
        $full = jeab_ordrar_compose_objekt_address($street, $placeComposed !== '' ? $placeComposed : $place);
        $row['address'] = $full;
        $row['huvudsokande_address'] = $full;
        $row['gata'] = $street;
        $row['postnr'] = $postFmt;
        $row['postnummer'] = $zipDigits;
        $row['ort'] = $ort;
    }
    if (array_key_exists('email', $input)) {
        $email = trim((string) $input['email']);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Ange en giltig e-postadress.');
        }
        $row['email'] = $email;
    }
    if (array_key_exists('phone', $input)) {
        $phoneRaw = trim((string) $input['phone']);
        if ($phoneRaw === '') {
            $row['phone'] = '';
        } elseif (function_exists('jeab_normalize_reminder_phone')) {
            $row['phone'] = (string) jeab_normalize_reminder_phone($phoneRaw);
        } else {
            $phone = function_exists('jeab_normalize_booking_phone')
                ? jeab_normalize_booking_phone($phoneRaw)
                : $phoneRaw;
            if ($phone === null || $phone === '') {
                throw new InvalidArgumentException('Ange ett svenskt mobilnummer (07… eller +467…).');
            }
            $row['phone'] = $phone;
        }
    }
    if (!is_array($row['applicant'] ?? null)) {
        $row['applicant'] = ['name' => trim((string) ($row['name'] ?? '')), 'email' => '', 'phone' => ''];
    }
    if (array_key_exists('email', $row)) {
        $row['applicant']['email'] = (string) $row['email'];
    }
    if (array_key_exists('phone', $row)) {
        $row['applicant']['phone'] = (string) $row['phone'];
    }
    if (array_key_exists('natbolag', $input)) {
        $nat = trim((string) $input['natbolag']);
        if (in_array($nat, jeab_ordrar_natbolag_options(), true)) {
            $row['natbolag'] = $nat;
        }
    }
    // Fastighet sparas här. Personnummer sparas på ägarkortet (Bekräfta). Ingen bokning, mejl, sms eller Fortnox.
    if (array_key_exists('objekt_fastighetsbeteckning', $input)) {
        $row['fastighetsbeteckning'] = trim((string) $input['objekt_fastighetsbeteckning']);
    }
    // Ursprung per fält: ändrat för hand blir 'manual', oförändrat förslag från uppslaget 'lookup'.
    if (array_key_exists('objekt_fastighetsbeteckning', $input)) {
        $row = jeab_ordrar_mark_posted_identity($before, $row, 'fastighetsbeteckning', (string) ($input['objekt_fastighetsbeteckning_forslag'] ?? ''), $beforeKey);
    }
    if ($addressTouched) {
        $row = jeab_ordrar_mark_posted_identity($before, $row, 'postnummer', '', $beforeKey);
    }
    $beforeZipFill = $row;
    [$row] = jeab_ordrar_fill_postnummer($row);
    $row = jeab_ordrar_mark_lookup_fills($beforeZipFill, $row, jeab_ordrar_address_key($row));
    if (function_exists('jeab_contacts_sole_divert_order')) {
        $row = jeab_contacts_sole_divert_order($before, $row);
    }
    // Ny adress: nytt Lantmäteriet-uppslag. Oförändrad adress: inget uppslag.
    $row = jeab_ordrar_relookup_after_address_change($before, $row);
    $row['objekt_saved_at'] = jeab_now();
    $row['updated_at'] = jeab_now();
    $row['lm_lookup_error'] = null;
    $row = jeab_purchase_put($row);
    jeab_ordrar_sync_lm_contact($row);

    return $row;
}

function jeab_ordrar_bestallning_has_lines(array $row): bool
{
    if (jeab_ordrar_orderrader_saved($row)) {
        return true;
    }
    foreach (is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [] as $line) {
        if (is_array($line) && jeab_ordrar_line_has_goods($line)) {
            return true;
        }
    }
    return false;
}

/** Tom sträng, Lightleap-ordernummer, eller «other» när en rad är märkt Lightleap. */
function jeab_ordrar_bestallning_lightleap(array $row): string
{
    $no = jeab_ordrar_shop_order_no($row, [], 'lightleap');
    if ($no !== '') {
        return $no;
    }
    foreach (is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [] as $line) {
        if (!is_array($line) || !jeab_ordrar_line_has_goods($line)) {
            continue;
        }
        $shop = jeab_ordrar_normalize_shop((string) ($line['shop'] ?? ''));
        if ($shop === 'lightleap' || $shop === 'aprilice|lightleap') {
            return 'other';
        }
    }
    $rowShop = jeab_ordrar_normalize_shop((string) ($row['shop'] ?? ''));
    if ($rowShop === 'lightleap' || $rowShop === 'aprilice|lightleap') {
        return 'other';
    }
    return '';
}

/**
 * Beställningens läge på statussidan.
 *
 * @return array{key:string,label:string,tone:string,delivery:string,aprilice:string,lightleap:string,ordered:string}
 */
function jeab_ordrar_bestallning_lage(array $row, ?JeabStore $store = null): array
{
    $aprilice = jeab_ordrar_shop_order_no($row, [], 'aprilice');
    $light = jeab_ordrar_bestallning_lightleap($row);
    $manual = jeab_ordrar_bestallning_status($row) === 'C' && jeab_ordrar_bestalld($row);
    $klar = $aprilice !== '' || ($light !== '' && $light !== 'other') || $manual;
    if (!$klar && $light === 'other' && jeab_ordrar_bestalld($row)) {
        $klar = true;
    }
    $empty = [
        'key' => 'rader',
        'label' => 'Väntar på orderrader',
        'tone' => 'warn',
        'delivery' => '',
        'aprilice' => '',
        'lightleap' => '',
        'ordered' => '',
    ];
    if (!$klar) {
        if (!jeab_ordrar_bestallning_has_lines($row)) {
            return $empty;
        }
        $empty['key'] = 'bokmalen';
        $empty['label'] = 'Väntar på Bokmalen';
        $empty['tone'] = 'brand';
        return $empty;
    }
    $delivery = jeab_ordrar_status_date_label(jeab_ordrar_actual_delivery($row));
    if ($delivery === '') {
        $delivery = jeab_ordrar_status_date_label(jeab_ordrar_onskad_leverans($row, $store instanceof JeabStore ? $store : null));
    }
    return [
        'key' => 'klar',
        'label' => 'Beställning klar',
        'tone' => 'ok',
        'delivery' => $delivery,
        'aprilice' => $aprilice,
        'lightleap' => $light,
        'ordered' => jeab_ordrar_status_datetime_label(jeab_ordrar_bestalld_when($row)),
    ];
}

/**
 * Fullmakt på lägeskortet. SEOM behövs och länkar till blanketten.
 * Övriga nätägare utom Ellevio är Behövs ej.
 *
 * @return array{key:string,label:string,tone:string,href:string}
 */
function jeab_ordrar_fullmakt_lage(array $row, string $href = ''): array
{
    if (function_exists('jeab_seom_fullmakt_applies') && jeab_seom_fullmakt_applies($row)) {
        $key = jeab_seom_fullmakt_status($row);
        $label = match ($key) {
            'kund_signerad' => 'Signerad',
            'kund_skickad' => 'Skickad till kund',
            'sam_skickad' => 'Skickad till Sam',
            default => 'Behövs',
        };
        $tone = match ($key) {
            'kund_signerad' => 'ok',
            'kund_skickad', 'sam_skickad' => 'brand',
            default => 'warn',
        };
        return ['key' => $key === 'ej_paborjad' ? 'behovs' : $key, 'label' => $label, 'tone' => $tone, 'href' => $href];
    }
    $key = function_exists('jeab_ellevio_fullmakt_status') ? jeab_ellevio_fullmakt_status($row) : '';
    $label = function_exists('jeab_ellevio_fullmakt_status_label')
        ? jeab_ellevio_fullmakt_status_label($key)
        : '';
    $tone = match ($key) {
        'signerad' => 'ok',
        'skickad', 'tittat' => 'brand',
        'ej_paborjad', 'vantar' => 'warn',
        default => 'muted',
    };
    return ['key' => $key, 'label' => $label !== '' ? $label : '–', 'tone' => $tone, 'href' => ''];
}

function jeab_ordrar_status_html(array $open, string $company, int $openId, mixed $store = null): string
{
    $open = jeab_ordrar_display_row($open);
    $store = $store instanceof JeabStore ? $store : null;
    $address = jeab_ordrar_status_address_lines($open);
    $nat = jeab_ordrar_status_natbolag($open);
    $contact = jeab_ordrar_status_contact($open, $openId);
    $identity = jeab_ordrar_status_identity($open, $openId);
    $objektAction = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'status']);
    $bookedRaw = jeab_ordrar_install_start($open, $store);
    $booked = jeab_ordrar_status_date_label($bookedRaw);
    $wanted = jeab_ordrar_status_date_label(jeab_ordrar_onskad_leverans($open, $store));
    $shops = [
        'Aprilice' => jeab_ordrar_shop_order_no($open, [], 'aprilice'),
        'Lightleap' => jeab_ordrar_shop_order_no($open, [], 'lightleap'),
        'Fortnox' => trim((string) ($open['fortnox_order'] ?? '')),
    ];
    $calUrl = $booked !== '' ? jeab_ordrar_booking_cal_url($open, $store) : '';

    $kind = jeab_ordrar_ui_kind($open['kind'] ?? '');
    $doc = ($kind === 'sol' && function_exists('jeab_solkoll_status_for_row'))
        ? jeab_solkoll_status_for_row($open)
        : ['key' => '', 'label' => ''];
    $docKey = trim((string) ($doc['key'] ?? ''));
    $docLabel = trim((string) ($doc['label'] ?? ''));
    $docTone = match ($docKey) {
        'slutford' => 'ok',
        'paborjad' => 'warn',
        'ny' => 'brand',
        default => 'muted',
    };

    $full = jeab_ordrar_fullmakt_lage($open, jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'eon']));
    $fullLabel = $full['label'];
    $fullTone = $full['tone'];

    $foranKey = jeab_ordrar_foranmalan_case_status($open);
    $foranLabel = jeab_ordrar_foranmalan_case_label($open);
    $foranTone = match ($foranKey) {
        'fardiganmald' => 'ok',
        'godkand', 'skickad' => 'brand',
        'nekad' => 'danger',
        default => 'muted',
    };
    $foranNote = jeab_ordrar_status_comment($open);
    if ($foranNote === '') {
        $foranNote = 'Ingen kommentar';
    }

    $fx = jeab_ordrar_fakturera_status($open);
    $fxLabel = match ($fx) {
        'faktura_skapad' => 'Faktura skapad',
        'order_skapad' => 'Order skriven',
        default => '',
    };
    $fxTone = match ($fx) {
        'faktura_skapad' => 'ok',
        'order_skapad' => 'warn',
        default => 'muted',
    };

    $docKicker = $kind === 'sol' ? 'DOKUMENTATION (Solkoll)' : 'Dokumentation';
    $cards = [];
    if ($kind !== 'batteri') {
        $cards[] = ['kicker' => $docKicker, 'kicker_as_written' => $kind === 'sol', 'label' => $docLabel !== '' ? $docLabel : '–', 'note' => '', 'tone' => $docTone];
    }
    $fullCard = ['kicker' => 'Fullmakt', 'label' => $fullLabel !== '' ? $fullLabel : '–', 'note' => '', 'tone' => $fullTone];
    if ($full['href'] !== '') {
        $fullCard['href'] = $full['href'];
        $fullCard['link'] = 'Öppna SEOM-fullmakt';
    }
    $cards[] = $fullCard;
    $cards[] = ['kicker' => 'Föranmälan', 'label' => $foranLabel !== '' ? $foranLabel : '–', 'note' => $foranNote, 'tone' => $foranTone, 'href' => jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'eon'])];
    $cards[] = ['kicker' => 'Fakturering', 'label' => $fxLabel !== '' ? $fxLabel : '–', 'note' => '', 'tone' => $fxTone];

    ob_start();
    ?>
    <section class="ordrar-status-card" data-js="ordrar-pane" data-stop="status">
        <h2>Status</h2>
        <?php $lmErr = trim((string) ($open['lm_lookup_error'] ?? '')); ?>
        <?php if ($lmErr !== ''): ?>
            <p class="ordrar-help" role="status" data-lm-manual><?= e((string) jeab_ordrar_scrub_pnr($lmErr)) ?></p>
        <?php endif; ?>
        <div class="ordrar-status-top">
            <form class="ordrar-status-objekt ordrar-objekt-form" method="post" action="<?= e($objektAction) ?>">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="ordrar.objekt">
                <input type="hidden" name="id" value="<?= (int) $openId ?>">
                <?php if (($identity['fastighetsbeteckning_forslag'] ?? '') !== ''): ?>
                    <input type="hidden" name="objekt_fastighetsbeteckning_forslag" value="<?= e((string) $identity['fastighetsbeteckning_forslag']) ?>">
                <?php endif; ?>
                <p class="ordrar-kicker">Objekt</p>
                <label class="ordrar-objekt-field">
                    <span class="visually-hidden">Adress</span>
                    <input class="ordrar-objekt-street" type="text" name="objekt_adress" value="<?= e($address['street']) ?>" placeholder="Adress" autocomplete="street-address">
                </label>
                <div class="ordrar-objekt-addr">
                    <label class="ordrar-objekt-field ordrar-objekt-zip">
                        <span>Postnummer</span>
                        <input class="ordrar-objekt-postnr" type="text" name="objekt_postnummer" value="<?= e($address['postnummer']) ?>" placeholder="123 45" autocomplete="postal-code" inputmode="numeric" maxlength="7">
                    </label>
                    <label class="ordrar-objekt-field">
                        <span class="visually-hidden">Ort</span>
                        <input class="ordrar-objekt-place" type="text" name="objekt_postort" value="<?= e($address['place']) ?>" placeholder="Ort" autocomplete="address-level2">
                    </label>
                </div>
                <label class="ordrar-objekt-line">
                    <span>Mobil</span>
                    <input type="tel" name="phone" value="<?= e($contact['phone']) ?>" placeholder="07…" autocomplete="tel" inputmode="tel">
                </label>
                <label class="ordrar-objekt-line">
                    <span>E-post</span>
                    <input type="email" name="email" value="<?= e($contact['email']) ?>" placeholder="namn@example.com" autocomplete="email" inputmode="email">
                </label>
                <label class="ordrar-objekt-line">
                    <span>Fastighet</span>
                    <input type="text" name="objekt_fastighetsbeteckning" value="<?= e($identity['fastighetsbeteckning']) ?>" placeholder="Fastighetsbeteckning" autocomplete="off" aria-label="Fastighetsbeteckning">
                </label>
                <?php if (function_exists('jeab_fastighet_refresh_button')): ?>
                    <div class="ordrar-fast-refresh"><?= jeab_fastighet_refresh_button($openId) ?></div>
                <?php endif; ?>
                <label class="ordrar-objekt-line">
                    <span>Nätägare</span>
                    <select class="ordrar-objekt-pill" name="natbolag">
                        <?php if ($nat === ''): ?>
                            <option value="" selected>Välj</option>
                        <?php endif; ?>
                        <?php foreach (jeab_ordrar_natbolag_options() as $option): ?>
                            <option value="<?= e($option) ?>"<?= $nat === $option ? ' selected' : '' ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="ordrar-primary ordrar-objekt-save" type="submit">Spara</button>
            </form>
            <div class="ordrar-status-datum">
                <p class="ordrar-kicker">Datum &amp; order</p>
                <?php if ($booked !== ''): ?>
                    <div class="ordrar-datum-row is-line">
                        <span class="ordrar-datum-lbl">Bokat installationsdatum</span>
                        <span class="ordrar-datum-val"><?= e($booked) ?></span>
                        <?php if ($calUrl !== ''): ?>
                            <a class="ordrar-cal" href="<?= e($calUrl) ?>" aria-label="Öppna bokningen i kalendern"><?= jeab_ordrar_status_cal_svg() ?></a>
                        <?php else: ?>
                            <span class="ordrar-cal" aria-hidden="true"><?= jeab_ordrar_status_cal_svg() ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($wanted !== ''): ?>
                    <div class="ordrar-datum-row">
                        <span class="ordrar-datum-lbl">Önskat leveransdatum</span>
                        <span class="ordrar-datum-val"><?= e($wanted) ?></span>
                        <span class="ordrar-datum-pad" aria-hidden="true"></span>
                    </div>
                <?php endif; ?>
                <?php foreach ($shops as $shopLabel => $shopNo): ?>
                    <?php if ($shopNo === '') { continue; } ?>
                    <div class="ordrar-datum-row">
                        <span class="ordrar-datum-lbl"><?= e($shopLabel) ?></span>
                        <span class="ordrar-datum-val"><?= e($shopNo) ?></span>
                        <span class="ordrar-datum-pad" aria-hidden="true"></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (jeab_ordrar_is_solbatteri($open)): ?>
            <?php $bestallLage = jeab_ordrar_bestallning_lage($open, $store); ?>
            <section class="ordrar-status-bestall" data-bestallning="<?= e($bestallLage['key']) ?>">
                <p class="ordrar-kicker">Beställning</p>
                <p class="ordrar-bestall-label"><?= e($bestallLage['label']) ?></p>
                <span class="ordrar-lage-dot is-<?= e($bestallLage['tone']) ?>" aria-hidden="true"></span>
                <?php if ($bestallLage['key'] === 'klar'): ?>
                    <div class="ordrar-bestall-facts">
                        <div class="ordrar-bestall-row">
                            <span>Leveransdatum</span>
                            <span><?= e($bestallLage['delivery'] !== '' ? $bestallLage['delivery'] : '–') ?></span>
                        </div>
                        <div class="ordrar-bestall-row">
                            <span>Aprilice</span>
                            <span><?= e($bestallLage['aprilice'] !== '' ? $bestallLage['aprilice'] : '–') ?></span>
                        </div>
                        <div class="ordrar-bestall-row">
                            <span>Beställd</span>
                            <span><?= e($bestallLage['ordered'] !== '' ? $bestallLage['ordered'] : '–') ?></span>
                        </div>
                        <?php if ($bestallLage['lightleap'] !== '' && $bestallLage['lightleap'] !== 'other'): ?>
                            <div class="ordrar-bestall-row">
                                <span>Lightleap</span>
                                <span><?= e($bestallLage['lightleap']) ?></span>
                            </div>
                        <?php elseif ($bestallLage['lightleap'] === 'other'): ?>
                            <p class="ordrar-bestall-note">Något är beställt från Lightleap</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <div class="ordrar-status-lage">
            <p class="ordrar-kicker">Läge</p>
            <div class="ordrar-lage-grid">
                <?php foreach ($cards as $card): ?>
                    <article class="ordrar-lage-card">
                        <p class="ordrar-lage-kicker<?= !empty($card['kicker_as_written']) ? ' is-as-written' : '' ?>"><?= e((string) $card['kicker']) ?></p>
                        <p class="ordrar-lage-label"><?= e((string) $card['label']) ?></p>
                        <?php if ((string) $card['note'] !== ''): ?>
                            <p class="ordrar-lage-note"><?= e((string) $card['note']) ?></p>
                        <?php endif; ?>
                        <?php if (trim((string) ($card['href'] ?? '')) !== ''): ?>
                            <a class="ordrar-lage-link" href="<?= e((string) $card['href']) ?>"><?= e(trim((string) ($card['link'] ?? '')) !== '' ? (string) $card['link'] : 'Öppna föranmälan') ?></a>
                        <?php endif; ?>
                        <span class="ordrar-lage-dot is-<?= e((string) $card['tone']) ?>" aria-hidden="true"></span>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        <form class="ordrar-stang" method="post" action="<?= e(jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'status'])) ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="ordrar.stang">
            <input type="hidden" name="id" value="<?= (int) $openId ?>">
            <button class="ordrar-stang-link" type="submit">Allt är fakturerat och klart, stäng ärendet</button>
        </form>
    </section>
    <?php
    return (string) ob_get_clean();
}

function jeab_ordrar_radera_html(int $id, string $company, string $label = 'Radera ärendet'): string
{
    if ($id <= 0) {
        return '';
    }
    $url = jeab_ordrar_url(['co' => $company]);
    return '<form class="ordrar-radera" method="post" action="' . e($url) . '" onsubmit="return confirm(\'Radera den här ordern?\');">'
        . jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.delete">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button class="ordrar-delete-link" type="submit">' . e($label) . '</button>'
        . '</form>';
}

function jeab_ordrar_delete(array $input, ?JeabStore $store = null): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $reminderId = (int) ($row['reminder_id'] ?? $input['id'] ?? 0);
    if (!$store instanceof JeabStore) {
        $store = jeab_ordrar_resolve_store();
    }
    $hadVunnen = false;
    if ($store instanceof JeabStore) {
        $reminderNow = jeab_get_reminder($store, $reminderId);
        $hadVunnen = is_array($reminderNow) && in_array('vunnen', jeab_parse_tags($reminderNow['tags'] ?? []), true);
    }
    // Kopia för Ångra (ordrar.restore). Sparas före raderingen, i SQL.
    jeab_ordrar_delete_undo_save($row, $hadVunnen, $store);
    $GLOBALS['_JEAB_ORDRAR_DELETE_LOCK'] = 1;
    try {
        jeab_ordrar_deleted_mark($reminderId, $store);
        jeab_ordrar_delete_audit($reminderId);
        jeab_purchase_delete($reminderId);
        if ($store instanceof JeabStore) {
            jeab_ordrar_untag_vunnen($reminderId, $store);
        }
    } finally {
        unset($GLOBALS['_JEAB_ORDRAR_DELETE_LOCK']);
    }

    return $row;
}

function jeab_ordrar_deleted_path(): string
{
    return jeab_data_file('ordrar-deleted.json');
}

function jeab_ordrar_deleted_normalize(array $doc): array
{
    $ids = [];
    foreach (is_array($doc['ids'] ?? null) ? $doc['ids'] : [] as $key => $row) {
        $id = (int) $key;
        if ($id <= 0) {
            continue;
        }
        $hs = [];
        $rawHs = is_array($row) ? ($row['hs'] ?? []) : [];
        foreach (is_array($rawHs) ? $rawHs : [] as $one) {
            $hsId = function_exists('jeab_reminder_hs_id_ok') ? jeab_reminder_hs_id_ok((string) $one) : null;
            if ($hsId !== null && !in_array($hsId, $hs, true)) {
                $hs[] = $hsId;
            }
        }
        $entry = [
            'at' => is_array($row) ? trim((string) ($row['at'] ?? '')) : '',
            'hs' => $hs,
            'booking_id' => is_array($row) ? (int) ($row['booking_id'] ?? 0) : 0,
        ];
        $by = is_array($row) ? trim((string) ($row['by'] ?? '')) : '';
        if ($by !== '') {
            $entry['by'] = mb_substr($by, 0, 191);
        }
        $ids[(string) $id] = $entry;
    }
    $hsMap = [];
    foreach (is_array($doc['hs'] ?? null) ? $doc['hs'] : [] as $key => $reminderId) {
        $hsId = function_exists('jeab_reminder_hs_id_ok') ? jeab_reminder_hs_id_ok((string) $key) : null;
        if ($hsId === null) {
            continue;
        }
        $hsMap[$hsId] = (int) $reminderId;
    }
    foreach ($ids as $id => $row) {
        foreach ($row['hs'] as $hsId) {
            if (!isset($hsMap[$hsId])) {
                $hsMap[$hsId] = (int) $id;
            }
        }
    }
    return ['ids' => $ids, 'hs' => $hsMap];
}

function jeab_ordrar_deleted_doc(): array
{
    if (!function_exists('jeab_ordrar_deleted_sql_pdo')) {
        require_once __DIR__ . '/ordrar_deleted_store.php';
    }
    if (jeab_ordrar_deleted_storage_mode() === 'sql') {
        $pdo = jeab_ordrar_deleted_sql_pdo();
        if ($pdo instanceof PDO && function_exists('jeab_sql_doc_get')) {
            $doc = jeab_sql_doc_get($pdo, JEAB_ORDRAR_DELETED_DOC_KEY);
            if (is_array($doc)) {
                return jeab_ordrar_deleted_normalize($doc);
            }
        }

        return ['ids' => [], 'hs' => []];
    }
    $path = jeab_ordrar_deleted_path();
    if (!is_file($path)) {
        return ['ids' => [], 'hs' => []];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    return jeab_ordrar_deleted_normalize(is_array($raw) ? $raw : []);
}

function jeab_ordrar_deleted_save(array $doc): void
{
    $doc = jeab_ordrar_deleted_normalize($doc);
    if (!function_exists('jeab_ordrar_deleted_sql_pdo')) {
        require_once __DIR__ . '/ordrar_deleted_store.php';
    }
    if (jeab_ordrar_deleted_storage_mode() === 'sql') {
        $pdo = jeab_ordrar_deleted_sql_pdo();
        if ($pdo instanceof PDO && function_exists('jeab_sql_doc_put')) {
            jeab_sql_doc_put($pdo, JEAB_ORDRAR_DELETED_DOC_KEY, $doc);
            return;
        }
        if (function_exists('jeab_legacy_json_skip') && jeab_legacy_json_skip('ordrar_deleted')) {
            return;
        }
        throw new RuntimeException('Kunde inte spara raderingen.');
    }
    if (function_exists('jeab_legacy_json_skip') && jeab_legacy_json_skip('ordrar_deleted')) {
        return;
    }
    $path = jeab_ordrar_deleted_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte spara raderingen.');
    }
    $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Kunde inte spara raderingen.');
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte spara raderingen.');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Kunde inte spara raderingen.');
    }
}

function jeab_ordrar_deleted_mark(int $reminderId, ?JeabStore $store = null): void
{
    if ($reminderId <= 0) {
        return;
    }
    $hs = [];
    $bookingId = 0;
    if ($store instanceof JeabStore) {
        $reminder = jeab_get_reminder($store, $reminderId);
        if (is_array($reminder)) {
            $hs = jeab_reminder_hs_ids_from_text(jeab_reminder_notes_text($reminder));
            $bookingId = (int) ($reminder['booking_id'] ?? 0);
        }
    }
    $doc = jeab_ordrar_deleted_doc();
    $marked = [
        'at' => function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
        'hs' => $hs,
        'booking_id' => $bookingId,
    ];
    $by = jeab_ordrar_deleted_actor();
    if ($by !== '') {
        $marked['by'] = $by;
    }
    $doc['ids'][(string) $reminderId] = $marked;
    foreach ($hs as $hsId) {
        $doc['hs'][$hsId] = $reminderId;
    }
    jeab_ordrar_deleted_save($doc);
}

function jeab_ordrar_deleted_actor(): string
{
    $by = '';
    if (function_exists('jeab_auth_user_email')) {
        $email = jeab_auth_user_email();
        if (is_string($email) && trim($email) !== '') {
            $by = trim($email);
        }
    }
    if ($by === '' && function_exists('jeab_auth_user_person')) {
        $person = jeab_auth_user_person();
        if (is_array($person)) {
            $by = trim((string) ($person['name'] ?? ''));
            if ($by === '') {
                $by = trim((string) ($person['id'] ?? ''));
            }
        }
    }

    return $by === '' ? '' : mb_substr($by, 0, 191);
}

function jeab_ordrar_delete_audit(int $reminderId): void
{
    if ($reminderId <= 0) {
        return;
    }
    $pdo = function_exists('jeab_ordrar_owner_audit_pdo') ? jeab_ordrar_owner_audit_pdo() : null;
    if (!$pdo instanceof PDO || !function_exists('jeab_ensure_order_delete_audit')) {
        return;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    jeab_ensure_order_delete_audit($pdo, $driver === 'mysql' ? 'mysql' : 'sqlite');
    $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO order_delete_audit (created_at, reminder_id, actor) VALUES (?, ?, ?)')->execute([
        $at,
        $reminderId,
        jeab_ordrar_deleted_actor(),
    ]);
}

/** @return list<array<string, mixed>> */
function jeab_ordrar_delete_audit_list(): array
{
    $pdo = function_exists('jeab_ordrar_owner_audit_pdo') ? jeab_ordrar_owner_audit_pdo() : null;
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $rows = $pdo->query('SELECT created_at, reminder_id, actor FROM order_delete_audit ORDER BY id')->fetchAll();
    } catch (Throwable) {
        return [];
    }

    return is_array($rows) ? $rows : [];
}

function jeab_ordrar_deleted_blocks(int $reminderId, ?array $reminder = null): bool
{
    $doc = jeab_ordrar_deleted_doc();
    if ($reminderId > 0 && isset($doc['ids'][(string) $reminderId])) {
        return true;
    }
    if (!is_array($reminder)) {
        return false;
    }
    foreach (jeab_reminder_hs_ids_from_text(jeab_reminder_notes_text($reminder)) as $hsId) {
        if (isset($doc['hs'][$hsId])) {
            return true;
        }
    }
    return false;
}

/**
 * Sant när spärren sitter kvar även efter att just den här radens markör tas bort.
 * Annan rads Help Scout-nummer ska fortfarande säga att ärendet är raderat.
 */
function jeab_ordrar_deleted_blocks_foreign(int $reminderId, ?array $reminder): bool
{
    $doc = jeab_ordrar_deleted_doc();
    unset($doc['ids'][(string) $reminderId]);
    foreach ($doc['hs'] as $hsId => $owner) {
        if ((int) $owner === $reminderId) {
            unset($doc['hs'][$hsId]);
        }
    }
    if (!is_array($reminder)) {
        return false;
    }
    foreach (jeab_reminder_hs_ids_from_text(jeab_reminder_notes_text($reminder)) as $hsId) {
        if (isset($doc['hs'][$hsId])) {
            return true;
        }
    }

    return false;
}

/**
 * Explicit #vunnen på en påminnelse som finns kvar tar bort just den radens spärr.
 * En annan raderad rads Help Scout-nummer ligger kvar.
 */
function jeab_ordrar_deleted_release(int $reminderId): void
{
    if ($reminderId <= 0) {
        return;
    }
    $doc = jeab_ordrar_deleted_doc();
    $changed = false;
    if (isset($doc['ids'][(string) $reminderId])) {
        unset($doc['ids'][(string) $reminderId]);
        $changed = true;
    }
    foreach ($doc['hs'] as $hsId => $owner) {
        if ((int) $owner === $reminderId) {
            unset($doc['hs'][$hsId]);
            $changed = true;
        }
    }
    if ($changed) {
        jeab_ordrar_deleted_save($doc);
    }
}

/**
 * Adress, produkt och kontakt som Vunnen ska skriva på ordern.
 * Kontakten först, sedan påminnelsens egen kopia. Rubriken räknas inte som namn.
 *
 * @return array{address:string,kind:string,name:string,email:string,phone:string,city:string,postal:string,contact_id:string}
 */
function jeab_ordrar_vunnen_source(array $reminder): array
{
    $shown = function_exists('jeab_reminder_shown_customer')
        ? jeab_reminder_shown_customer($reminder)
        : ['name' => '', 'email' => '', 'phone' => '', 'street' => '', 'postal' => '', 'city' => '', 'display' => ''];
    $address = trim((string) ($shown['street'] ?? ''));
    if ($address === '') {
        $address = trim((string) ($reminder['address'] ?? ''));
    }
    if ($address === '') {
        $display = trim((string) ($shown['display'] ?? ''));
        if ($display !== '' && preg_match('/\d/u', $display) === 1) {
            $address = $display;
        }
    }
    $email = trim((string) ($shown['email'] ?? ''));
    if ($email === '' && function_exists('jeab_reminder_email')) {
        $email = jeab_reminder_email($reminder);
    }
    if ($email === '') {
        $email = trim((string) ($reminder['email'] ?? ''));
    }
    $phone = trim((string) ($shown['phone'] ?? ''));
    if ($phone === '' && function_exists('jeab_reminder_phone')) {
        $phone = jeab_reminder_phone($reminder);
    }
    if ($phone === '') {
        $phone = trim((string) ($reminder['phone'] ?? ''));
    }
    $name = trim((string) ($shown['name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($reminder['customer_name'] ?? ''));
    }
    $city = trim((string) ($shown['city'] ?? ''));
    if ($city === '') {
        $city = trim((string) ($reminder['ort'] ?? ''));
    }
    $postal = trim((string) ($shown['postal'] ?? ''));
    if ($postal === '') {
        $postal = trim((string) ($reminder['postal'] ?? ''));
    }
    $kind = jeab_ordrar_vunnen_products($reminder)[0] ?? '';

    return [
        'address' => $address,
        'kind' => $kind,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'city' => $city,
        'postal' => $postal,
        'contact_id' => trim((string) ($reminder['contact_id'] ?? '')),
    ];
}

/**
 * Produkter i den ordning de står. Ett exakt fält (kind, produkt, …) är den primära.
 * «batteri till solceller» är bara batteri. Flera träffar: den första blir orderns produkt.
 *
 * @return list<string>
 */
function jeab_ordrar_vunnen_products(array $reminder): array
{
    $exact = '';
    $field = '';
    foreach (['kind', 'job_type', 'job', 'produkt', 'product', 'vad'] as $key) {
        if (!array_key_exists($key, $reminder)) {
            continue;
        }
        $raw = $reminder[$key];
        if (is_array($raw)) {
            $raw = implode(' ', array_map('strval', $raw));
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            continue;
        }
        $norm = function_exists('jeab_purchase_normalize_kind') ? jeab_purchase_normalize_kind($raw) : null;
        if ($norm !== null && $exact === '') {
            $exact = $norm;
        }
        $field .= "\n" . $raw;
    }
    $body = '';
    foreach (['title', 'note', 'notes'] as $key) {
        $body .= "\n" . trim((string) ($reminder[$key] ?? ''));
    }
    $hits = jeab_ordrar_vunnen_kind_hits(jeab_ordrar_vunnen_strip_battery_to_solar($field . "\n" . $body));
    $primary = $exact !== '' ? $exact : ($hits[0] ?? '');
    if ($primary === '' && function_exists('jeab_inkorg_reminder_offer') && function_exists('jeab_purchase_normalize_kind')) {
        $primary = jeab_purchase_normalize_kind(jeab_inkorg_reminder_offer($reminder)) ?? '';
    }
    $out = [];
    foreach (array_merge([$primary], $exact !== '' ? [] : $hits) as $kind) {
        if ($kind !== '' && !in_array($kind, $out, true)) {
            $out[] = $kind;
        }
    }

    return $out;
}

/** Tar bort solordet i «batteri till solceller», så det inte räknas som en andra produkt. */
function jeab_ordrar_vunnen_strip_battery_to_solar(string $text): string
{
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

    return preg_replace(
        '/batter\p{L}*\s+till\s+(?:(?:mina|min|vår|var|våra|vara|befintlig(?:a)?|redan|de|dom)\s+){0,3}(?:solcell\p{L}*|solpanel\p{L}*|solanlägg\p{L}*|solanlagg\p{L}*|(?<![\p{L}])paneler(?![\p{L}]))/u',
        'batteri',
        $text
    ) ?? $text;
}

/**
 * Första träffen av varje produkt, i textordning.
 *
 * @return list<string>
 */
function jeab_ordrar_vunnen_kind_hits(string $text): array
{
    $specs = [
        'laddbox' => ['elbilsladdare', 'laddbox'],
        'batteri' => ['batterilagring', 'batteri'],
        'sol' => ['solceller', 'solpaneler', 'solcell', 'sol'],
    ];
    $found = [];
    foreach ($specs as $kind => $words) {
        $pos = null;
        foreach ($words as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u', $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $at = (int) $m[0][1];
            if ($pos === null || $at < $pos) {
                $pos = $at;
            }
        }
        if ($pos !== null) {
            $found[$pos] = $kind;
        }
    }
    ksort($found);

    return array_values($found);
}

/** Flera produkter. Ordern får den första, men den bokas inte. */
function jeab_ordrar_vunnen_product_ambiguous(array $reminder): bool
{
    return count(jeab_ordrar_vunnen_products($reminder)) > 1;
}

/**
 * Adress och kontakt krävs för att #vunnen ska skapa ordern.
 * Produkten väljs i Bekräfta (första steget). Tom produkt är ingen lucka.
 *
 * @param array{address?:string,kind?:string,name?:string,email?:string} $source
 * @return list<string>
 */
function jeab_ordrar_vunnen_gaps(array $source, array $reminder = []): array
{
    $gaps = [];
    if (trim((string) ($source['address'] ?? '')) === '') {
        $gaps[] = 'adress';
    }
    if (trim((string) ($source['name'] ?? '')) === '' && trim((string) ($source['email'] ?? '')) === '') {
        $gaps[] = 'kontakt';
    }

    return $gaps;
}

/** @param list<string> $gaps */
function jeab_ordrar_vunnen_gap_list(array $gaps): string
{
    if ($gaps === []) {
        return '';
    }
    if (count($gaps) === 1) {
        return $gaps[0];
    }
    $last = array_pop($gaps);

    return implode(', ', $gaps) . ' och ' . $last;
}

/** @param list<string> $gaps */
function jeab_ordrar_vunnen_gap_message(array $gaps): string
{
    $list = jeab_ordrar_vunnen_gap_list($gaps);
    if ($list === '') {
        return '';
    }

    return 'Ordern skapades inte. Saknar ' . $list . '.';
}

/**
 * Text vid Vunnen-knappen innan klick. Tom när taggen redan är på eller när allt finns.
 * Adress och kontakt läses via jeab_ordrar_vunnen_source (Kontakter först).
 */
function jeab_ordrar_vunnen_need_label(array $reminder, bool $alreadyOn = false): string
{
    if ($alreadyOn) {
        return '';
    }
    $list = jeab_ordrar_vunnen_gap_list(
        jeab_ordrar_vunnen_gaps(jeab_ordrar_vunnen_source($reminder), $reminder)
    );
    if ($list === '') {
        return '';
    }

    return 'Saknar ' . $list . '.';
}

/**
 * Fyller bara tomma fält. En rubrik som hamnat i namnet byts mot kontaktens namn.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $source
 * @param array<string, mixed> $reminder
 * @return array<string, mixed>
 */
function jeab_ordrar_apply_vunnen_source(array $row, array $source, array $reminder = []): array
{
    $title = trim((string) ($reminder['title'] ?? ''));
    $name = trim((string) ($source['name'] ?? ''));
    $current = trim((string) ($row['name'] ?? ''));
    $nameIsTitle = $title !== '' && $current === $title && $name !== '' && $name !== $title;
    if ($name !== '' && ($current === '' || $nameIsTitle)) {
        $row['name'] = $name;
        $huvud = trim((string) ($row['huvudsokande'] ?? ''));
        if ($huvud === '' || $huvud === $title) {
            $row['huvudsokande'] = $name;
        }
        if (!is_array($row['applicant'] ?? null)) {
            $row['applicant'] = ['name' => '', 'email' => '', 'phone' => ''];
        }
        $app = trim((string) ($row['applicant']['name'] ?? ''));
        if ($app === '' || $app === $title) {
            $row['applicant']['name'] = $name;
        }
    }
    $email = trim((string) ($source['email'] ?? ''));
    if ($email !== '' && trim((string) ($row['email'] ?? '')) === '') {
        $row['email'] = $email;
    }
    $phone = trim((string) ($source['phone'] ?? ''));
    if ($phone !== '' && function_exists('jeab_normalize_booking_phone')) {
        $phone = jeab_normalize_booking_phone($phone) ?? $phone;
    }
    if ($phone !== '' && trim((string) ($row['phone'] ?? '')) === '') {
        $row['phone'] = $phone;
    }
    $address = trim((string) ($source['address'] ?? ''));
    $addressEmpty = trim((string) ($row['address'] ?? '')) === '';
    if ($address !== '' && $addressEmpty) {
        $row['address'] = $address;
        if (trim((string) ($row['huvudsokande_address'] ?? '')) === '') {
            $row['huvudsokande_address'] = $address;
        }
    }
    $city = trim((string) ($source['city'] ?? ''));
    if ($city !== '' && trim((string) ($row['ort'] ?? '')) === '') {
        $row['ort'] = $city;
    }
    $postal = trim((string) ($source['postal'] ?? ''));
    if ($postal !== '' && trim((string) ($row['postnummer'] ?? '')) === '' && trim((string) ($row['postnr'] ?? '')) === '') {
        $row['postnummer'] = $postal;
    }
    $contactId = trim((string) ($source['contact_id'] ?? ''));
    if ($contactId !== '' && trim((string) ($row['contact_id'] ?? '')) === '') {
        $row['contact_id'] = $contactId;
    }
    $kind = function_exists('jeab_purchase_normalize_kind')
        ? (jeab_purchase_normalize_kind($source['kind'] ?? '') ?? '')
        : trim((string) ($source['kind'] ?? ''));
    $have = function_exists('jeab_purchase_normalize_kind')
        ? (jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '')
        : trim((string) ($row['kind'] ?? ''));
    if ($kind !== '' && $have === '') {
        $row['kind'] = $kind;
    }

    return $row;
}

/** Vunnen från klicket. Saknad adress eller kontakt tar bort taggen igen och skapar ingen order. */
function jeab_ordrar_vunnen_from_tag(JeabStore $store, array $row): array
{
    $rid = (int) ($row['id'] ?? 0);
    try {
        $purchase = jeab_ordrar_vunnen_purchase($store, $rid, $row);
    } catch (InvalidArgumentException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Ärendet är raderat.' || str_starts_with($msg, 'Ordern skapades inte')) {
            jeab_toggle_reminder_tag($store, $rid, 'vunnen');
        }
        throw $e;
    }
    // Ordern först, sedan klarmarkeras påminnelsen. Kastar ordern ovan blir påminnelsen öppen.
    jeab_ordrar_vunnen_close_reminder($store, $rid);

    return $purchase;
}

/**
 * Klarmarkerar påminnelsen när dess order finns. Idempotent: redan klar lämnas orörd.
 * Saknas ordern görs inget.
 */
function jeab_ordrar_vunnen_close_reminder(JeabStore $store, int $reminderId): ?array
{
    if ($reminderId <= 0 || !is_array(jeab_purchase_get($reminderId))) {
        return null;
    }
    $live = jeab_get_reminder($store, $reminderId);
    if (!is_array($live)) {
        return null;
    }
    if (!empty($live['completed_at'])) {
        return $live;
    }

    return jeab_toggle_reminder($store, $reminderId, true);
}

function jeab_ordrar_vunnen_purchase(JeabStore $store, int $reminderId, array $reminder): array
{
    $run = static function () use ($store, $reminderId, $reminder): array {
        return jeab_ordrar_vunnen_purchase_locked($store, $reminderId, $reminder);
    };
    if ($reminderId > 0 && function_exists('jeab_reminder_lock')) {
        return jeab_reminder_lock($reminderId, $run);
    }
    return $run();
}

function jeab_ordrar_vunnen_purchase_locked(JeabStore $store, int $reminderId, array $reminder): array
{
    // Tombstonen lyfts först när ordern finns. Ett kast (saknad adress eller
    // kontakt, eller någon annans Help Scout-nummer) lämnar ids och hs kvar.
    // Tom produkt skapar ändå ordern. Produkten väljs i Bekräfta.
    $blocked = jeab_ordrar_deleted_blocks($reminderId, $reminder);
    if ($blocked) {
        $fresh = jeab_get_reminder($store, $reminderId);
        if (is_array($fresh)) {
            $reminder = $fresh;
        }
        if (jeab_ordrar_deleted_blocks_foreign($reminderId, $reminder)) {
            throw new InvalidArgumentException('Ärendet är raderat.');
        }
    }
    $fresh = jeab_get_reminder($store, $reminderId);
    if (is_array($fresh)) {
        $reminder = $fresh;
    }
    $source = jeab_ordrar_vunnen_source($reminder);
    $purchase = jeab_purchase_get($reminderId);
    $existed = is_array($purchase);
    if (!is_array($purchase)) {
        $gaps = jeab_ordrar_vunnen_gaps($source, $reminder);
        if ($gaps !== []) {
            throw new InvalidArgumentException(jeab_ordrar_vunnen_gap_message($gaps));
        }
        // Bara den egna spärren får kringgås, och den lyfts först när ordern finns.
        $purchase = jeab_purchase_start($store, $reminderId, (string) $source['kind'], $blocked);
    } else {
        $filled = jeab_ordrar_apply_vunnen_source($purchase, $source, $reminder);
        if (jeab_ordrar_stored_owner_names($filled) === []) {
            $filled = jeab_ordrar_apply_reminder_owner($filled, $reminder);
        }
        if ($filled !== $purchase) {
            $filled['updated_at'] = jeab_now();
            $purchase = jeab_purchase_put($filled);
        }
    }
    if ($blocked) {
        jeab_ordrar_deleted_release($reminderId);
    }
    if ($existed && !is_array(jeab_purchase_get($reminderId))) {
        throw new RuntimeException('Ordern kan bara raderas via Radera.');
    }
    // Taggning öppnar ordern. jeab_ordrar_vunnen_from_tag klarmarkerar sedan
    // påminnelsen när ordern finns.
    $live = jeab_get_reminder($store, $reminderId);
    if (!is_array($live)) {
        $live = $reminder;
    }
    return jeab_ordrar_autobook_meab_won($store, $live, $purchase);
}

/** @param list<mixed> $hsIds */
function jeab_ordrar_deleted_blocks_hs(array $hsIds): bool
{
    $doc = jeab_ordrar_deleted_doc();
    foreach ($hsIds as $raw) {
        $hsId = function_exists('jeab_reminder_hs_id_ok') ? jeab_reminder_hs_id_ok((string) $raw) : null;
        if ($hsId !== null && isset($doc['hs'][$hsId])) {
            return true;
        }
    }
    return false;
}

function jeab_ordrar_tags_without_vunnen(mixed $tags): array
{
    return array_values(array_filter(
        jeab_parse_tags($tags),
        static fn (string $tag): bool => $tag !== 'vunnen'
    ));
}

/** Drop vunnen so a later taggning inte ser ut som en öppen affär. Tombstonen stoppar återskapandet. */
function jeab_ordrar_untag_vunnen(int $reminderId, ?JeabStore $store = null): void
{
    if ($reminderId <= 0) {
        return;
    }
    if (!$store instanceof JeabStore) {
        $store = jeab_ordrar_resolve_store();
    }
    if (!$store instanceof JeabStore) {
        return;
    }
    $reminder = jeab_get_reminder($store, $reminderId);
    if (!is_array($reminder)) {
        return;
    }
    $tags = jeab_parse_tags($reminder['tags'] ?? []);
    if (!in_array('vunnen', $tags, true)) {
        return;
    }
    $tags = array_values(array_filter(
        $tags,
        static fn (string $tag): bool => $tag !== 'vunnen'
    ));
    jeab_update_reminder($store, $reminderId, ['tags' => $tags]);
}

/** @return array{b14:int,b3:int,sokande:int,b15:int,laddare:int}|null */
function jeab_ordrar_kalkyl_from_input(array $input): ?array
{
    if (!array_key_exists('kalkyl_present', $input)) {
        return null;
    }
    $flag = static function (mixed $value): int {
        if (is_array($value)) {
            $value = end($value);
        }
        return jeab_offert_num($value) > 0 ? 1 : 0;
    };
    $out = [
        'b14' => max(0, (int) round(jeab_offert_num($input['b14'] ?? 0))),
        'b3' => max(0, (int) round(jeab_offert_num($input['b3'] ?? 0))),
        'sokande' => max(1, (int) round(jeab_offert_num($input['sokande'] ?? 1))),
        'b15' => $flag($input['b15'] ?? 0),
        'laddare' => $flag($input['laddare'] ?? 0),
    ];
    $typ = strtolower(trim((string) ($input['sigen_typ'] ?? '')));
    if ($typ !== '' && function_exists('jeab_sigen_resolve')) {
        $choice = jeab_sigen_resolve($typ, (string) ($input['sigen_kw'] ?? ''), $out['b3']);
        if ($choice === null) {
            throw new InvalidArgumentException('Välj en växelriktarstorlek som finns för den typen.');
        }
        $out['b3'] = $choice['qty'];
        if (!array_key_exists('b14', $input)) {
            $out['b14'] = 0;
        }
        $out['b15'] = $choice['type'] === 'tp2' ? 1 : 0;
        $out['sigen_typ'] = $choice['type'];
        $out['sigen_kw'] = $choice['kw'];
    }
    return $out;
}

function jeab_ordrar_kalkyl_html(array $row): string
{
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    $input = jeab_offert_kalkyl_input($row, 'sol');
    $calc = jeab_offert_calc($input);
    $standard = jeab_offert_load_standard();
    $prices = is_array($standard['offert'] ?? null) ? $standard['offert'] : [];
    $pris = (int) ($row['pris'] ?? 0);
    if ($pris <= 0) {
        $pris = (int) ($row['kundpris'] ?? 0);
    }
    $auto = $pris > 0 ? '0' : '1';
    $hiddenKeys = ['c4', 'b5', 'c13', 'b17', 'c27', 'c28', 'tp2_unit', 'not_tp2_unit', 'laddare_unit', 'laddare_hours', 'hourly', 'g27'];
    ob_start();
    ?>
    <div class="offert ordrar-kalkyl" data-js="offert"<?= $kind === 'sol' ? '' : ' hidden' ?>>
        <input type="hidden" name="kalkyl_present" value="1">
        <div class="offert-daily">
            <?= jeab_offert_daily_controls_html($calc) ?>
        </div>
        <?php foreach ($hiddenKeys as $key): ?>
            <input type="hidden" data-in="<?= e($key) ?>" value="<?= e(jeab_offert_int((float) ($calc[$key] ?? $prices[$key] ?? 0))) ?>">
        <?php endforeach; ?>
        <input type="hidden" data-in="c23" data-auto="<?= e($auto) ?>" value="<?= $pris > 0 ? e(jeab_offert_int((float) $pris)) : '' ?>">
        <p class="offert-margins" data-out="margins"><?= e(jeab_offert_margins_label($calc)) ?></p>
        <section class="offert-card">
            <p><span>Batteri</span><b data-out="i10"><?= e(jeab_offert_money((float) $calc['i10'])) ?></b></p>
            <p><span>Solpaneler</span><b data-out="i11"><?= e(jeab_offert_money((float) $calc['i11'])) ?></b></p>
            <p><span>Schablon</span><b data-out="i12"><?= e(jeab_offert_money((float) $calc['i12'])) ?></b></p>
            <p class="offert-strong"><span>Att betala</span><b data-out="i14"><?= e(jeab_offert_money((float) $calc['i14'])) ?></b></p>
        </section>
    </div>
    <?php
    $html = (string) ob_get_clean();
    if ($kind === 'batteri' && function_exists('jeab_offert_schablon_kronor')) {
        $batteryCalc = jeab_offert_calc(jeab_offert_kalkyl_input($row, 'batteri'));
        $schablon = jeab_offert_schablon_kronor($batteryCalc, 'batteri');
        $html .= '<section class="offert-card ordrar-schablon"><p><span>Schablon</span><b data-out="h29">'
            . e(jeab_offert_money((float) $schablon)) . '</b></p></section>';
    }

    return $html;
}

/**
 * Klarmarkerar eller ångrar klarmarkeringen.
 * Skriver bara manual_done och en rad i aktivitetsloggen.
 * Rör inte påminnelse, bokning, Fortnox, mejl eller sms, och raderar ingenting.
 */
function jeab_ordrar_manual_done(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $undo = (string) ($input['undo'] ?? '') === '1';
    $on = jeab_ordrar_manual_done_on($row);
    // Redan klarmarkerad, eller redan utan flagga: ingen ny rad och ingen skrivning.
    if ($undo !== $on) {
        return $row;
    }
    $at = jeab_now();
    $who = jeab_ordrar_manual_done_actor();
    $when = jeab_ordrar_manual_done_when($at);
    if ($undo) {
        $row['manual_done'] = null;
    } else {
        $row['manual_done'] = [
            'by' => $who,
            'at' => $at,
        ];
    }
    $row = jeab_ordrar_activity_append($row, jeab_ordrar_manual_done_log($who, $when, $undo), $at, '', false);
    $row['updated_at'] = $at;

    return jeab_purchase_put($row);
}

function jeab_ordrar_stang(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    if (array_key_exists('done', $input) && (string) $input['done'] === '0') {
        $row['allt_klart'] = false;
        $row['closed_at'] = '';
        $row['updated_at'] = jeab_now();
        return jeab_purchase_put($row);
    }
    if ((string) ($input['v3'] ?? '') === '1' && !jeab_ordrar_has_invoice($row)) {
        // Nya vyn: ärendet stängs först när fakturan finns.
        throw new InvalidArgumentException('Fakturan finns inte i Fortnox än. Skapa fakturan först.');
    }
    $row['allt_klart'] = true;
    if (trim((string) ($row['closed_at'] ?? '')) === '') {
        $row['closed_at'] = jeab_now();
    }
    $row['updated_at'] = jeab_now();
    return jeab_purchase_put($row);
}

function jeab_ordrar_is_utkast_post(array $input): bool
{
    if (!array_key_exists('utkast', $input)) {
        return false;
    }
    $raw = strtolower(trim((string) $input['utkast']));
    return !in_array($raw, ['0', 'off', 'false', ''], true);
}

function jeab_ordrar_normalize_shop(string $shop): string
{
    $shop = strtolower(trim($shop));
    if ($shop === 'aprilice|lightleap' || $shop === 'lightleap|aprilice') {
        return 'aprilice|lightleap';
    }
    if (str_contains($shop, 'light')) {
        return 'lightleap';
    }
    if (str_contains($shop, 'april')) {
        return 'aprilice';
    }
    return '';
}

function jeab_ordrar_line_has_goods(array $line): bool
{
    $qty = $line['qty'] ?? 0;
    return trim((string) ($line['sku'] ?? '')) !== ''
        || trim((string) ($line['title'] ?? '')) !== ''
        || (is_numeric($qty) && (int) $qty > 0);
}

function jeab_ordrar_shop_summary(array $row): string
{
    $hasApril = false;
    $hasLight = false;
    foreach (is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [] as $line) {
        if (!is_array($line) || !jeab_ordrar_line_has_goods($line)) {
            continue;
        }
        $shop = jeab_ordrar_normalize_shop((string) ($line['shop'] ?? ''));
        if ($shop === 'lightleap' || $shop === 'aprilice|lightleap') {
            $hasLight = true;
        }
        if ($shop !== 'lightleap') {
            $hasApril = true;
        }
    }
    $rowShop = jeab_ordrar_normalize_shop((string) ($row['shop'] ?? ''));
    if (!$hasApril && !$hasLight) {
        return $rowShop;
    }
    if ($hasLight && $hasApril) {
        return 'aprilice|lightleap';
    }
    if ($hasLight) {
        return $rowShop === 'aprilice' || $rowShop === 'aprilice|lightleap' ? 'aprilice|lightleap' : 'lightleap';
    }
    return 'aprilice';
}

function jeab_ordrar_shop_label(array $row): string
{
    if (!jeab_ordrar_bestalld($row)) {
        return '';
    }
    $sum = jeab_ordrar_shop_summary($row);
    if ($sum === 'lightleap' || $sum === 'aprilice|lightleap') {
        return 'Något är också på Lightleap';
    }
    return 'Allt är på Aprilice';
}

function jeab_ordrar_shops_used(array $row): array
{
    $used = [];
    foreach (is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [] as $line) {
        if (!is_array($line) || !jeab_ordrar_line_has_goods($line)) {
            continue;
        }
        $shop = jeab_ordrar_normalize_shop((string) ($line['shop'] ?? ''));
        if ($shop === 'lightleap' || $shop === 'aprilice|lightleap') {
            $used['lightleap'] = true;
        }
        if ($shop === 'aprilice' || $shop === 'aprilice|lightleap') {
            $used['aprilice'] = true;
        }
    }
    $sum = jeab_ordrar_shop_summary($row);
    if ($sum === 'lightleap' || $sum === 'aprilice|lightleap') {
        $used['lightleap'] = true;
    }
    if ($sum === 'aprilice' || $sum === 'aprilice|lightleap') {
        $used['aprilice'] = true;
    }
    if ($used === []) {
        $used['aprilice'] = true;
    }
    $out = [];
    foreach (['aprilice', 'lightleap'] as $shop) {
        if (!empty($used[$shop])) {
            $out[] = $shop;
        }
    }
    return $out;
}

function jeab_ordrar_line_shop_matches(array $line, string $shop): bool
{
    $want = jeab_ordrar_normalize_shop($shop);
    $have = jeab_ordrar_normalize_shop((string) ($line['shop'] ?? ''));
    if ($want === '' || $have === '') {
        return false;
    }
    if ($have === $want) {
        return true;
    }
    return $have === 'aprilice|lightleap' && ($want === 'aprilice' || $want === 'lightleap');
}

/**
 * Enstaka lagda Aprilice-ordrar där sync shop_facts aldrig landade.
 * Tomma fält fylls när ordern öppnas. Ett sparat nummer lämnas orört.
 *
 * @return list<array{id:int,name:string,aprilice:string,bestalld_at:string,leverans:string}>
 */
function jeab_ordrar_known_shop_orders(): array
{
    return [
        [
            'id' => 23,
            'name' => 'adolfsson',
            'aprilice' => '2026104078',
            'bestalld_at' => '2026-09-29 13:00:00',
            'leverans' => '2026-10-06',
        ],
        [
            'id' => 71,
            'name' => 'karlsson',
            'aprilice' => '2026104339',
            'bestalld_at' => '2026-10-01 10:01:00',
            'leverans' => '2026-10-07',
        ],
    ];
}

function jeab_ordrar_known_shop_order_match(array $row): ?array
{
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        $id = (int) ($row['id'] ?? 0);
    }
    if ($id <= 0) {
        return null;
    }
    $name = (string) ($row['name'] ?? '') . ' ' . (string) ($row['huvudsokande'] ?? '') . ' ' . (string) ($row['customer_name'] ?? '');
    $addr = (string) ($row['address'] ?? '') . ' ' . (string) ($row['huvudsokande_address'] ?? '') . ' ' . (string) ($row['objekt_adress'] ?? '');
    $folded = function_exists('mb_strtolower') ? mb_strtolower($name . ' ' . $addr, 'UTF-8') : strtolower($name . ' ' . $addr);
    foreach (jeab_ordrar_known_shop_orders() as $known) {
        if ((int) ($known['id'] ?? 0) !== $id) {
            continue;
        }
        $needle = strtolower(trim((string) ($known['name'] ?? '')));
        if ($needle !== '' && !str_contains($folded, $needle)) {
            continue;
        }
        // Krokusvägen 10: adressnål så fel Karlsson inte fylls.
        if ($id === 71 && !str_contains($folded, 'krokus')) {
            continue;
        }
        return $known;
    }
    return null;
}

function jeab_ordrar_is_adolfsson_order(array $row): bool
{
    $known = jeab_ordrar_known_shop_order_match($row);
    return is_array($known) && (int) ($known['id'] ?? 0) === 23;
}

function jeab_ordrar_known_aprilice_order(array $row): string
{
    $known = jeab_ordrar_known_shop_order_match($row);
    return is_array($known) ? trim((string) ($known['aprilice'] ?? '')) : '';
}

function jeab_ordrar_shop_order_scalar(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }
    return trim((string) $value);
}

function jeab_ordrar_shop_order_no(array $row, array $input, string $shop): string
{
    $found = jeab_ordrar_shop_order_no_stored($row, $input, $shop);
    if ($found !== '') {
        return $found;
    }
    if (jeab_ordrar_normalize_shop($shop) === 'aprilice') {
        return jeab_ordrar_known_aprilice_order($row);
    }
    return '';
}

function jeab_ordrar_shop_order_no_stored(array $row, array $input, string $shop): string
{
    $shop = jeab_ordrar_normalize_shop($shop);
    if ($shop !== 'aprilice' && $shop !== 'lightleap') {
        return '';
    }
    foreach ([$shop . '_order', $shop . '_ordernr', $shop . '_order_no', $shop . '_shop_order'] as $key) {
        $value = jeab_ordrar_shop_order_scalar($input[$key] ?? '');
        if ($value !== '') {
            return $value;
        }
        $value = jeab_ordrar_shop_order_scalar($row[$key] ?? '');
        if ($value !== '') {
            return $value;
        }
    }
    foreach (['shops', 'shop_orders', 'webshops'] as $bagKey) {
        foreach ([$input[$bagKey] ?? null, $row[$bagKey] ?? null] as $bag) {
            if (!is_array($bag) || !array_key_exists($shop, $bag)) {
                continue;
            }
            $entry = $bag[$shop];
            $value = jeab_ordrar_shop_order_scalar($entry);
            if ($value !== '') {
                return $value;
            }
            if (!is_array($entry)) {
                continue;
            }
            foreach (['order_no', 'ordernr', 'shop_order', 'order', 'number', 'ordernummer'] as $key) {
                $value = jeab_ordrar_shop_order_scalar($entry[$key] ?? '');
                if ($value !== '') {
                    return $value;
                }
            }
        }
    }
    foreach (is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [] as $line) {
        if (!is_array($line) || !jeab_ordrar_line_has_goods($line) || !jeab_ordrar_line_shop_matches($line, $shop)) {
            continue;
        }
        foreach (['order_no', 'ordernr', 'shop_order', 'order', 'order_number', 'ordernummer'] as $key) {
            $value = jeab_ordrar_shop_order_scalar($line[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }
    }
    $label = $shop === 'lightleap' ? 'Lightleap' : 'Aprilice';
    foreach (jeab_ordrar_activity_list($row) as $item) {
        if (preg_match('/^' . $label . '\s+(\S+)$/u', (string) ($item['text'] ?? ''), $m) === 1) {
            return $m[1];
        }
    }
    return '';
}

function jeab_ordrar_bestalld_when(array $row): string
{
    $at = trim((string) ($row['bestalld_at'] ?? ''));
    if ($at !== '') {
        return $at;
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    $day = trim((string) ($steps['bestalld'] ?? ''));
    if ($day !== '') {
        return $day;
    }
    $known = jeab_ordrar_known_shop_order_match($row);
    if (is_array($known)) {
        return trim((string) ($known['bestalld_at'] ?? ''));
    }
    return '';
}

/**
 * Kända lagda Aprilice-ordrar (Adolfsson #23, Krokusvägen 10 #71 m.fl.).
 * Numret kom aldrig in via sync shop_facts, så statuskortet stod kvar på «Väntar på Bokmalen».
 * Tomt fält fylls när ordern öppnas. Ett sparat nummer lämnas orört.
 *
 * @return array{0:array,1:bool}
 */
function jeab_ordrar_fill_known_shop_order(array $row): array
{
    $known = jeab_ordrar_known_shop_order_match($row);
    if (!is_array($known)) {
        return [$row, false];
    }
    $aprilice = trim((string) ($known['aprilice'] ?? ''));
    $when = trim((string) ($known['bestalld_at'] ?? ''));
    $leverans = trim((string) ($known['leverans'] ?? ''));
    $day = '';
    if ($when !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $when, $m) === 1) {
        $day = $m[1];
    }
    $changed = false;
    if ($aprilice !== '' && jeab_ordrar_shop_order_no_stored($row, [], 'aprilice') === '') {
        $row['aprilice_order'] = $aprilice;
        $changed = true;
    }
    if ($leverans !== '') {
        foreach (['leverans', 'faktisk_leverans', 'onskad_leverans'] as $key) {
            if (trim((string) ($row[$key] ?? '')) === '') {
                $row[$key] = $leverans;
                $changed = true;
            }
        }
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    $step = trim((string) ($steps['bestalld'] ?? ''));
    if ($when !== '' && trim((string) ($row['bestalld_at'] ?? '')) === '') {
        $row['bestalld_at'] = $when;
        $changed = true;
    }
    if ($day !== '' && $step === '') {
        $steps['bestalld'] = $day;
        $row['steps'] = $steps;
        $changed = true;
    }
    return [$row, $changed];
}

function jeab_ordrar_persist_known_shop_order(array $row): array
{
    $before = $row;
    [$filled, $changed] = jeab_ordrar_fill_known_shop_order($row);
    if (!$changed) {
        return $row;
    }
    $id = (int) ($filled['reminder_id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_purchase_get') || !is_array(jeab_purchase_get($id))) {
        return $filled;
    }
    $delta = [];
    foreach ($filled as $key => $value) {
        if ($key === 'reminder_id' || $key === 'updated_at' || $key === 'install_note' || $key === 'install_note_rev') {
            continue;
        }
        if (!array_key_exists($key, $before) || $before[$key] !== $value) {
            $delta[$key] = $value;
        }
    }
    if ($delta === [] || !function_exists('jeab_purchase_patch')) {
        $filled['updated_at'] = function_exists('jeab_now') ? jeab_now() : (string) ($filled['updated_at'] ?? '');

        return function_exists('jeab_ordrar_put_leaving_note') ? jeab_ordrar_put_leaving_note($filled) : $filled;
    }
    // Bara de nya shopfälten, ovanpå den låsta raden. En gammal kopia får inte skriva över anteckning eller Fortnox.
    $saved = jeab_purchase_patch($id, static function (array $current) use ($delta): ?array {
        $wrote = false;
        foreach ($delta as $key => $value) {
            if ($key === 'steps' && is_array($value)) {
                $steps = is_array($current['steps'] ?? null) ? $current['steps'] : [];
                foreach ($value as $stepKey => $stepValue) {
                    if (!is_string($stepKey) || trim((string) ($steps[$stepKey] ?? '')) !== '') {
                        continue;
                    }
                    if (trim((string) $stepValue) === '') {
                        continue;
                    }
                    $steps[$stepKey] = $stepValue;
                    $wrote = true;
                }
                $current['steps'] = $steps;
                continue;
            }
            if (is_array($value) || trim((string) ($current[$key] ?? '')) !== '' || trim((string) $value) === '') {
                continue;
            }
            $current[$key] = $value;
            $wrote = true;
        }
        if (!$wrote) {
            return null;
        }
        $current['updated_at'] = function_exists('jeab_now') ? jeab_now() : (string) ($current['updated_at'] ?? '');

        return $current;
    });
    if (!is_array($saved)) {
        return $filled;
    }
    foreach ($delta as $key => $value) {
        if (array_key_exists($key, $saved)) {
            $filled[$key] = $saved[$key];
        }
    }
    $filled['updated_at'] = $saved['updated_at'] ?? ($filled['updated_at'] ?? '');

    return $filled;
}

/**
 * Shopnummer, aktivitet och signerat Ellevio-id som tidigare skrevs när ordern
 * öppnades. Öppning skriver inte längre. En POST som sparar ordern kör detta
 * direkt. Annars syns värdet vid nästa tick, inte i samma sidvisning.
 * Nätbolag här är bara det sparade värdet. Inget uppslag mot ArcGIS.
 */
function jeab_ordrar_repair_fingerprint(array $row): string
{
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    $keys = [];
    foreach (jeab_ordrar_activity_list($row) as $item) {
        $key = trim((string) ($item['key'] ?? ''));
        if ($key !== '') {
            $keys[$key] = true;
        }
    }
    ksort($keys);
    $bookingId = (int) ($row['booking_id'] ?? 0);
    $sms = '';
    $email = '';
    if ($bookingId > 0 && function_exists('jeab_ordrar_activity_booking_sent')) {
        $times = jeab_ordrar_activity_booking_sent($bookingId);
        $sms = (string) ($times['sms_at'] ?? '');
        $email = (string) ($times['email_at'] ?? '');
    }
    $parts = [
        trim((string) ($row['aprilice_order'] ?? '')),
        trim((string) ($row['bestalld_at'] ?? '')),
        trim((string) ($row['leverans'] ?? '')),
        trim((string) ($steps['foranmald'] ?? '')),
        trim((string) ($steps['bestalld'] ?? '')),
        (string) $bookingId,
        $sms,
        $email,
        implode(',', array_keys($keys)),
        trim((string) ($row['natbolag'] ?? '')),
        trim((string) ($row['fullmakt_token'] ?? '')),
        empty($row['fullmakt_signed']) ? '0' : '1',
        trim((string) ($row['anlaggnings_id'] ?? '')),
        trim((string) ($row['name'] ?? '')),
        trim((string) ($row['huvudsokande'] ?? '')),
        trim((string) ($row['address'] ?? '')),
    ];

    return hash('sha256', implode("\n", $parts));
}

function jeab_ordrar_repair_maybe(array $row): bool
{
    if ((int) ($row['reminder_id'] ?? 0) <= 0) {
        return false;
    }
    if (jeab_ordrar_allt_klart($row)) {
        return false;
    }
    $hold = trim((string) ($row['ordrar_repair_hold'] ?? ''));
    if ($hold !== '' && $hold === jeab_ordrar_repair_fingerprint($row)) {
        return false;
    }
    if (function_exists('jeab_ordrar_known_shop_order_match')) {
        $known = jeab_ordrar_known_shop_order_match($row);
        if (is_array($known)) {
            $aprilice = trim((string) ($known['aprilice'] ?? ''));
            if ($aprilice !== '' && function_exists('jeab_ordrar_shop_order_no_stored') && jeab_ordrar_shop_order_no_stored($row, [], 'aprilice') === '') {
                return true;
            }
            if (trim((string) ($known['bestalld_at'] ?? '')) !== '' && trim((string) ($row['bestalld_at'] ?? '')) === '') {
                return true;
            }
            if (trim((string) ($known['leverans'] ?? '')) !== '' && trim((string) ($row['leverans'] ?? '')) === '') {
                return true;
            }
        }
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    $keys = [];
    foreach (jeab_ordrar_activity_list($row) as $item) {
        $key = trim((string) ($item['key'] ?? ''));
        if ($key !== '') {
            $keys[$key] = true;
        }
    }
    if (trim((string) ($steps['foranmald'] ?? '')) !== '' && !isset($keys['foranmald'])) {
        return true;
    }
    $bookingId = (int) ($row['booking_id'] ?? 0);
    if ($bookingId > 0 && function_exists('jeab_ordrar_activity_booking_sent')) {
        $times = jeab_ordrar_activity_booking_sent($bookingId);
        if ($times['sms_at'] !== '' && !isset($keys['sms:' . $bookingId])) {
            return true;
        }
        if ($times['email_at'] !== '' && !isset($keys['mail:' . $bookingId])) {
            return true;
        }
    }
    // Bara sparat nätbolag. Ett tomt fält får inte slå upp Ellevio via HTTP.
    if (trim((string) ($row['natbolag'] ?? '')) === 'Ellevio') {
        $token = trim((string) ($row['fullmakt_token'] ?? ''));
        if ($token !== '' && empty($row['fullmakt_signed'])) {
            return true;
        }
        if (!empty($row['fullmakt_signed']) && trim((string) ($row['anlaggnings_id'] ?? '')) === '') {
            return true;
        }
    }

    return false;
}

function jeab_ordrar_repair_one(array $row): array
{
    $row = jeab_ordrar_persist_known_shop_order($row);
    $row = jeab_ordrar_activity_backfill($row, true);
    if (trim((string) ($row['natbolag'] ?? '')) === 'Ellevio' && function_exists('jeab_ellevio_fullmakt_pull_signed')) {
        $row = jeab_ellevio_fullmakt_pull_signed($row, false);
    }

    return $row;
}

function jeab_ordrar_repair_hold_if_stuck(array $row): void
{
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_purchase_patch')) {
        return;
    }
    $fresh = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
    if (!is_array($fresh)) {
        $fresh = $row;
    }
    $probe = $fresh;
    unset($probe['ordrar_repair_hold']);
    if (!jeab_ordrar_repair_maybe($probe)) {
        return;
    }
    $finger = jeab_ordrar_repair_fingerprint($fresh);
    if ((string) ($fresh['ordrar_repair_hold'] ?? '') === $finger) {
        return;
    }
    try {
        jeab_purchase_patch($id, static function (array $current) use ($finger): array {
            $current['ordrar_repair_hold'] = $finger;

            return $current;
        });
    } catch (Throwable) {
    }
}

function jeab_ordrar_repair_pdo(): ?PDO
{
    if (!function_exists('jeab_purchase_sql_live')) {
        return null;
    }
    $pdo = jeab_purchase_sql_live();

    return $pdo instanceof PDO ? $pdo : null;
}

function jeab_ordrar_repair_settings_ready(PDO $pdo, string $driver): void
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (isset($ready[$key])) {
        return;
    }
    $ready[$key] = true;
    if ($driver !== 'sqlite') {
        return;
    }
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    } catch (Throwable) {
    }
}

function jeab_ordrar_repair_cursor_get(): int
{
    $mem = max(0, (int) ($GLOBALS['_JEAB_ORDRAR_REPAIR_CURSOR'] ?? 0));
    $pdo = jeab_ordrar_repair_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_value')) {
        return $mem;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ordrar_repair_settings_ready($pdo, $driver);
    $raw = jeab_settings_value($pdo, $driver, 'ordrar_repair_cursor');
    if ($raw === null || $raw === '') {
        return $mem;
    }

    return max(0, (int) $raw);
}

function jeab_ordrar_repair_cursor_set(int $cursor): void
{
    $cursor = max(0, $cursor);
    $GLOBALS['_JEAB_ORDRAR_REPAIR_CURSOR'] = $cursor;
    $pdo = jeab_ordrar_repair_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ordrar_repair_settings_ready($pdo, $driver);
    jeab_settings_put($pdo, $driver, 'ordrar_repair_cursor', (string) $cursor);
}

/**
 * Öppna ordrar efter markören, högst $limit rader. Allt klart ingår inte.
 *
 * @return array{rows:list<array<string,mixed>>,last:int,raw:int}
 */
function jeab_ordrar_repair_fetch(int $afterId, int $limit): array
{
    $afterId = max(0, $afterId);
    $limit = max(1, $limit);
    $empty = ['rows' => [], 'last' => $afterId, 'raw' => 0];
    $pdo = jeab_ordrar_repair_pdo();
    if ($pdo instanceof PDO && function_exists('jeab_sql_purchases_decode')) {
        try {
            $stmt = $pdo->prepare(
                'SELECT reminder_id, payload FROM purchases WHERE reminder_id > ? AND klart = 0 ORDER BY reminder_id ASC LIMIT ' . $limit
            );
            $stmt->execute([$afterId]);
            $fetched = $stmt->fetchAll();
            $rows = [];
            $last = $afterId;
            $raw = 0;
            foreach ($fetched as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $raw++;
                $last = (int) ($row['reminder_id'] ?? $last);
                $data = jeab_sql_purchases_decode($row['reminder_id'] ?? 0, $row['payload'] ?? '');
                if (!is_array($data) || !empty($data['_jeab_list_skip'])) {
                    continue;
                }
                $rows[] = $data;
            }

            return ['rows' => $rows, 'last' => $last, 'raw' => $raw];
        } catch (Throwable) {
            // JSON-läget nedan när kolumnen saknas.
        }
    }
    if (!function_exists('jeab_purchase_all')) {
        return $empty;
    }
    $picked = [];
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? 0);
        if ($id <= $afterId || jeab_ordrar_allt_klart($row)) {
            continue;
        }
        $picked[$id] = $row;
    }
    ksort($picked);
    $slice = array_slice(array_values($picked), 0, $limit);
    $last = $afterId;
    if ($slice !== []) {
        $last = (int) ($slice[count($slice) - 1]['reminder_id'] ?? $afterId);
    }

    return ['rows' => $slice, 'last' => $last, 'raw' => count($slice)];
}

function jeab_ordrar_repair_saved(int $id): ?array
{
    if ($id <= 0 || !function_exists('jeab_purchase_get')) {
        return null;
    }
    $row = jeab_purchase_get($id);
    if (!is_array($row)) {
        return null;
    }

    return jeab_ordrar_repair_one($row);
}

/**
 * Högst $limit öppna rader per tick, inte hela tabellen. Allt klart hoppas över.
 * En rad som inte går att laga (osignerad fullmakt, signerad utan anläggnings-id)
 * märks så den inte tar platserna varje tick. Inte ett namngivet tick-jobb.
 */
function jeab_ordrar_repair_tick(int $limit = 25): int
{
    $limit = max(1, $limit);
    $cursor = jeab_ordrar_repair_cursor_get();
    $batch = jeab_ordrar_repair_fetch($cursor, $limit);
    if ($batch['raw'] === 0 && $cursor > 0) {
        $cursor = 0;
        $batch = jeab_ordrar_repair_fetch(0, $limit);
    }
    $done = 0;
    foreach ($batch['rows'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        try {
            if (!jeab_ordrar_repair_maybe($row)) {
                continue;
            }
            $repaired = jeab_ordrar_repair_one($row);
            jeab_ordrar_repair_hold_if_stuck($repaired);
            $done++;
        } catch (Throwable) {
        }
    }
    $next = $batch['raw'] < $limit ? 0 : (int) $batch['last'];
    jeab_ordrar_repair_cursor_set($next);

    return $done;
}

/**
 * Inköpskostnad lagras i kronor exklusive moms (samma bas som Aprilice redan
 * skickar och som bestall_kostnad har sparats i). Aprilice utan moms-flagga
 * räknas som exklusive och lagras oförändrat. Lightleap utan moms-flagga
 * räknas som inklusive 25 % moms och blir round(inkl / 1,25) innan den
 * summeras. moms=inkl eller moms=exkl på raden vinner över den regeln.
 * Orderns leveransdatum är det senaste datumet bland leverantörsraderna.
 * En rad per leverantör, nyckel leverantör + referens: samma nyckel igen
 * ändrar inte beloppet. Annan referens för samma leverantör är en konflikt.
 * En annan leverantör läggs till.
 */
function jeab_ordrar_supplier_exkl_moms(int $inkl): int
{
    if ($inkl <= 0) {
        return 0;
    }
    // 25 % moms: exkl = inkl / 1,25 = inkl * 4/5, närmaste krona.
    return intdiv($inkl * 4 + 2, 5);
}

function jeab_ordrar_supplier_moms_mode(string $supplier, string $explicit): string
{
    $explicit = strtolower(trim($explicit));
    if ($explicit === 'inkl' || $explicit === 'exkl') {
        return $explicit;
    }
    if (jeab_ordrar_normalize_shop($supplier) === 'lightleap') {
        return 'inkl';
    }
    return 'exkl';
}

/** @param array<string, mixed> $line */
function jeab_ordrar_supplier_line_canon(array $line): array
{
    $moms = (string) ($line['moms'] ?? '') === 'inkl' ? 'inkl' : 'exkl';
    $out = [
        'supplier' => jeab_ordrar_normalize_shop((string) ($line['supplier'] ?? $line['shop'] ?? '')),
        'reference' => trim((string) ($line['reference'] ?? '')),
        'amount' => max(0, (int) ($line['amount'] ?? 0)),
        'moms' => $moms,
    ];
    $inkl = (int) ($line['amount_inkl'] ?? 0);
    if ($moms === 'inkl' && $inkl > 0) {
        $out['amount_inkl'] = $inkl;
    }
    $leverans = jeab_ordrar_ymd((string) ($line['leverans'] ?? ''));
    if ($leverans !== '') {
        $out['leverans'] = $leverans;
    }
    return $out;
}

/** @return list<array<string, mixed>> */
function jeab_ordrar_supplier_lines_of(array $row): array
{
    $raw = $row['supplier_lines'] ?? null;
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $line) {
        if (!is_array($line)) {
            continue;
        }
        $canon = jeab_ordrar_supplier_line_canon($line);
        if ($canon['supplier'] === '' && $canon['reference'] === '' && $canon['amount'] <= 0 && !isset($canon['leverans'])) {
            continue;
        }
        $out[] = $canon;
    }
    return $out;
}

function jeab_ordrar_supplier_header_amount(array $row): int
{
    foreach (['bestall_kostnad', 'shop_kostnad'] as $key) {
        $raw = $row[$key] ?? null;
        if (is_int($raw) || is_float($raw)) {
            return max(0, (int) round((float) $raw));
        }
        if (is_string($raw)) {
            $clean = str_replace(["\u{00A0}", "\u{202F}", ' ', 'kr'], '', $raw);
            $clean = str_replace(',', '.', $clean);
            if (is_numeric($clean)) {
                return max(0, (int) round((float) $clean));
            }
        }
    }
    return 0;
}

/**
 * Saknas supplier_lines byggs en rad av det som redan ligger på ordern.
 * bestall_kostnad är redan exkl. moms och delas inte en gång till.
 *
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_supplier_seed(array $row): array
{
    if (jeab_ordrar_supplier_lines_of($row) !== []) {
        return jeab_ordrar_supplier_lines_of($row);
    }
    $amount = jeab_ordrar_supplier_header_amount($row);
    $supplier = '';
    $reference = '';
    $aprilice = trim((string) ($row['aprilice_order'] ?? ''));
    $lightleap = trim((string) ($row['lightleap_order'] ?? ''));
    if ($aprilice !== '') {
        $supplier = 'aprilice';
        $reference = $aprilice;
    } elseif ($lightleap !== '') {
        $supplier = 'lightleap';
        $reference = $lightleap;
    }
    $leverans = '';
    if ($supplier !== '' || (string) ($row['leverans_source'] ?? '') === 'shop') {
        $leverans = jeab_ordrar_ymd((string) ($row['leverans'] ?? $row['faktisk_leverans'] ?? ''));
    }
    if ($amount <= 0 && $leverans === '') {
        return [];
    }
    return [jeab_ordrar_supplier_line_canon([
        'supplier' => $supplier,
        'reference' => $reference,
        'amount' => $amount,
        'moms' => 'exkl',
        'leverans' => $leverans,
    ])];
}

function jeab_ordrar_supplier_lines_sum(array $lines): int
{
    $sum = 0;
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $sum += max(0, (int) ($line['amount'] ?? 0));
    }
    return $sum;
}

/** Senaste Y-m-d bland leverantörsraderna. Tom om ingen har datum. */
function jeab_ordrar_supplier_latest(array $lines): string
{
    $best = '';
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $day = jeab_ordrar_ymd((string) ($line['leverans'] ?? ''));
        if ($day !== '' && ($best === '' || $day > $best)) {
            $best = $day;
        }
    }
    return $best;
}

function jeab_ordrar_supplier_cost_sum(array $row): int
{
    return jeab_ordrar_supplier_lines_sum(jeab_ordrar_supplier_lines_of($row));
}

/**
 * @param array<string, mixed> $facts
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_supplier_facts_lines(array $facts): array
{
    $out = [];
    $nested = is_array($facts['lines'] ?? null) ? $facts['lines'] : [];
    foreach ($nested as $line) {
        if (!is_array($line)) {
            continue;
        }
        $built = jeab_ordrar_supplier_incoming_line($line, $facts, false);
        if ($built !== null) {
            $out[] = $built;
        }
    }
    $top = jeab_ordrar_supplier_incoming_line($facts, $facts, true);
    if ($top === null || $out !== [] && (string) $top['supplier'] === '') {
        return $out;
    }
    foreach ($out as $line) {
        if ((string) $line['supplier'] !== '' && (string) $line['supplier'] === (string) $top['supplier']) {
            return $out;
        }
    }
    $out[] = $top;
    return $out;
}

/**
 * @param array<string, mixed> $source
 * @param array<string, mixed> $facts
 * @return array<string, mixed>|null
 */
function jeab_ordrar_supplier_incoming_line(array $source, array $facts, bool $top): ?array
{
    $supplier = jeab_ordrar_normalize_shop((string) ($source['supplier'] ?? $source['shop'] ?? $source['leverantor'] ?? ''));
    if ($top && $supplier === '') {
        $aprilice = trim((string) ($facts['aprilice'] ?? ''));
        $lightleap = trim((string) ($facts['lightleap'] ?? ''));
        if ($aprilice !== '' && $lightleap === '') {
            $supplier = 'aprilice';
        } elseif ($lightleap !== '' && $aprilice === '') {
            $supplier = 'lightleap';
        }
    }
    $reference = trim((string) ($source['reference'] ?? $source['referens'] ?? ''));
    if ($reference === '' && $supplier === 'aprilice') {
        $reference = trim((string) ($source['aprilice'] ?? ($top ? ($facts['aprilice'] ?? '') : '')));
    }
    if ($reference === '' && $supplier === 'lightleap') {
        $reference = trim((string) ($source['lightleap'] ?? ($top ? ($facts['lightleap'] ?? '') : '')));
    }
    if (!$top) {
        foreach (['order_no', 'ordernr', 'order'] as $key) {
            if ($reference === '' && is_scalar($source[$key] ?? null)) {
                $reference = trim((string) $source[$key]);
            }
        }
    }
    $raw = 0;
    if ($top) {
        $cost = trim((string) ($source['kostnad'] ?? ''));
        if ($cost !== '' && ctype_digit($cost)) {
            $raw = (int) $cost;
        }
    } elseif (function_exists('jeab_bokmalen_ordrar_pick_amount')) {
        $picked = jeab_bokmalen_ordrar_pick_amount($source);
        if ($picked !== '' && ctype_digit($picked)) {
            $raw = (int) $picked;
        }
    }
    $leverans = jeab_ordrar_ymd((string) ($source['leverans'] ?? ''));
    if (!$top && $leverans === '' && function_exists('jeab_bokmalen_ordrar_pick_when')) {
        $leverans = jeab_ordrar_ymd(jeab_bokmalen_ordrar_pick_when($source, [
            'leverans', 'faktisk_leverans', 'delivery', 'delivery_date', 'leveransdatum', 'deliveryDate',
        ]));
    }
    if ($raw <= 0 && $leverans === '') {
        return null;
    }
    $explicit = strtolower(trim((string) ($source['moms'] ?? '')));
    if ($explicit !== 'inkl' && $explicit !== 'exkl' && function_exists('jeab_bokmalen_ordrar_moms_mode')) {
        $explicit = jeab_bokmalen_ordrar_moms_mode($source);
    }
    $moms = jeab_ordrar_supplier_moms_mode($supplier, $explicit);
    $amount = $raw;
    $inkl = 0;
    if ($raw > 0 && $moms === 'inkl') {
        $inkl = $raw;
        $amount = jeab_ordrar_supplier_exkl_moms($raw);
    }
    return jeab_ordrar_supplier_line_canon([
        'supplier' => $supplier,
        'reference' => $reference,
        'amount' => $amount,
        'moms' => $moms,
        'amount_inkl' => $inkl,
        'leverans' => $leverans,
    ]);
}

/**
 * @param list<array<string, mixed>> $lines
 * @param list<array<string, mixed>> $incoming
 * @return array{lines:list<array<string, mixed>>,cost_conflict:bool,stored_amount:int,sent_amount:int}
 */
function jeab_ordrar_supplier_lines_merge(array $row, array $lines, array $incoming): array
{
    $conflict = false;
    $storedAmount = 0;
    $sentAmount = 0;
    foreach ($incoming as $in) {
        if (!is_array($in)) {
            continue;
        }
        $supplier = (string) ($in['supplier'] ?? '');
        $reference = (string) ($in['reference'] ?? '');
        $amount = (int) ($in['amount'] ?? 0);
        $leverans = (string) ($in['leverans'] ?? '');
        $idx = null;
        foreach ($lines as $i => $line) {
            if ((string) ($line['supplier'] ?? '') === $supplier) {
                $idx = $i;
                break;
            }
        }
        $adopt = false;
        if ($idx === null && $supplier !== '' && count($lines) === 1 && (string) ($lines[0]['supplier'] ?? '') === '') {
            $haveAmount = (int) ($lines[0]['amount'] ?? 0);
            if ($amount > 0 && $haveAmount > 0 && $amount !== $haveAmount) {
                $other = $supplier === 'lightleap' ? 'aprilice' : 'lightleap';
                $lines[0]['supplier'] = $other;
                if ((string) ($lines[0]['reference'] ?? '') === '') {
                    $lines[0]['reference'] = trim((string) ($row[$other . '_order'] ?? ''));
                }
                $lines[0] = jeab_ordrar_supplier_line_canon($lines[0]);
                $lines[] = jeab_ordrar_supplier_line_canon($in);
                continue;
            }
            $idx = 0;
            $adopt = true;
        }
        if ($idx === null) {
            if ($supplier === '' && $amount <= 0) {
                // Datum utan leverantör är ingen ny rad. Samma eller tidigare datum ändrar inget.
                if ($leverans !== '' && $lines !== []) {
                    $latest = jeab_ordrar_supplier_latest($lines);
                    if ($latest !== $leverans && ($latest === '' || $leverans > $latest)) {
                        foreach ($lines as $i => $line) {
                            $day = (string) ($line['leverans'] ?? '');
                            if ($latest === '' || $day === $latest) {
                                $line['leverans'] = $leverans;
                                $lines[$i] = jeab_ordrar_supplier_line_canon($line);
                                break;
                            }
                        }
                    }
                }
                continue;
            }
            if ($amount <= 0 && $leverans === '') {
                continue;
            }
            $lines[] = jeab_ordrar_supplier_line_canon($in);
            continue;
        }
        $have = $lines[$idx];
        $haveRef = (string) ($have['reference'] ?? '');
        $haveAmount = (int) ($have['amount'] ?? 0);
        if (!$adopt && $reference !== '' && $haveRef !== '' && $reference !== $haveRef) {
            if ($amount > 0 && $haveAmount > 0 && $amount !== $haveAmount) {
                $conflict = true;
                $storedAmount = $haveAmount;
                $sentAmount = $amount;
            }
            continue;
        }
        if (!$adopt && $amount > 0 && $haveAmount > 0 && $amount !== $haveAmount) {
            $conflict = true;
            $storedAmount = $haveAmount;
            $sentAmount = $amount;
            if ($leverans !== '' && (string) ($have['leverans'] ?? '') !== $leverans) {
                $have['leverans'] = $leverans;
                $lines[$idx] = jeab_ordrar_supplier_line_canon($have);
            }
            continue;
        }
        if ($adopt) {
            $have['supplier'] = $supplier;
        }
        if ($haveRef === '' && $reference !== '') {
            $have['reference'] = $reference;
        }
        if ($amount > 0 && $haveAmount === 0) {
            $have['amount'] = $amount;
            $have['moms'] = (string) ($in['moms'] ?? 'exkl');
            if ((string) ($in['moms'] ?? '') === 'inkl' && (int) ($in['amount_inkl'] ?? 0) > 0) {
                $have['amount_inkl'] = (int) $in['amount_inkl'];
            }
        }
        if ($leverans !== '') {
            $have['leverans'] = $leverans;
        }
        $lines[$idx] = jeab_ordrar_supplier_line_canon($have);
    }
    return [
        'lines' => $lines,
        'cost_conflict' => $conflict,
        'stored_amount' => $storedAmount,
        'sent_amount' => $sentAmount,
    ];
}

/**
 * @param array<string, mixed> $facts
 * @return array{lines:list<array<string, mixed>>,cost_conflict:bool,stored_amount:int,sent_amount:int,incoming:list<array<string, mixed>>}
 */
function jeab_ordrar_supplier_merge_preview(array $row, array $facts): array
{
    $existing = jeab_ordrar_supplier_lines_of($row);
    $base = $existing !== [] ? $existing : jeab_ordrar_supplier_seed($row);
    $incoming = jeab_ordrar_supplier_facts_lines($facts);
    $merged = jeab_ordrar_supplier_lines_merge($row, $base, $incoming);
    $merged['incoming'] = $incoming;
    return $merged;
}

/**
 * Räknar om bestall_kostnad och leverans från sparade supplier_lines.
 * Skriver ingen Fortnox-order.
 *
 * @return array<string, mixed>
 */
function jeab_ordrar_supplier_recompute_row(array $row): array
{
    $lines = jeab_ordrar_supplier_lines_of($row);
    if ($lines === []) {
        return $row;
    }
    $next = $row;
    if (($row['supplier_lines'] ?? null) !== $lines) {
        $next['supplier_lines'] = $lines;
    }
    $sum = jeab_ordrar_supplier_lines_sum($lines);
    if ($sum > 0 && jeab_ordrar_supplier_header_amount($row) !== $sum) {
        $next['bestall_kostnad'] = $sum;
    }
    $latest = jeab_ordrar_supplier_latest($lines);
    if ($latest !== '') {
        $prev = trim((string) ($row['leverans'] ?? ''));
        if ($prev !== '' && $prev !== $latest && trim((string) ($row['leverans_planerad'] ?? '')) === '') {
            $next['leverans_planerad'] = $prev;
        }
        if ($prev !== $latest) {
            $next['leverans'] = $latest;
        }
        if (trim((string) ($row['faktisk_leverans'] ?? '')) !== $latest) {
            $next['faktisk_leverans'] = $latest;
        }
        if ((string) ($row['leverans_source'] ?? '') !== 'shop') {
            $next['leverans_source'] = 'shop';
        }
    }
    return $next;
}

/**
 * dry=1 är standard och skriver inget. order_id pekar på order 20 eller vilken order som helst.
 * Bara sparade supplier_lines. Ingen Fortnox-skrivning.
 *
 * POST /hook.php
 * Authorization: Bearer <inkorg-token>
 * {"action":"ordrar.supplier_lines_recompute","order_id":20}
 * {"action":"ordrar.supplier_lines_recompute","order_id":20,"dry":0}
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_supplier_lines_recompute(array $input): array
{
    $dry = function_exists('jeab_ordrar_backfill_wants_dry') ? jeab_ordrar_backfill_wants_dry($input) : true;
    $orderId = (int) ($input['order_id'] ?? $input['reminder_id'] ?? $input['id'] ?? 0);
    $base = [
        'action' => 'ordrar.supplier_lines_recompute',
        'dry' => 1,
        'order_id' => $orderId,
        'fortnox' => 'none',
        'cost_basis' => 'exkl_moms',
    ];
    if ($orderId <= 0) {
        return $base + ['ok' => false, 'error' => 'order_id', 'written' => 0];
    }
    if (!function_exists('jeab_purchase_get')) {
        return $base + ['ok' => false, 'error' => 'store', 'dry' => $dry ? 1 : 0, 'written' => 0];
    }
    $row = jeab_purchase_get($orderId);
    if (!is_array($row)) {
        return $base + ['ok' => false, 'error' => 'missing', 'dry' => $dry ? 1 : 0, 'written' => 0];
    }
    $lines = jeab_ordrar_supplier_lines_of($row);
    $next = jeab_ordrar_supplier_recompute_row($row);
    $changed = $next !== $row;
    $written = 0;
    if (!$dry && $changed && function_exists('jeab_purchase_put')) {
        $next['updated_at'] = function_exists('jeab_now') ? jeab_now() : ($next['updated_at'] ?? '');
        $saved = jeab_purchase_put($next);
        if (is_array($saved)) {
            $next = $saved;
        }
        $written = 1;
    }
    $sum = $lines === [] ? jeab_ordrar_supplier_header_amount($row) : jeab_ordrar_supplier_lines_sum($lines);
    $latest = jeab_ordrar_supplier_latest($lines);
    $headerDate = jeab_ordrar_ymd((string) ($row['leverans'] ?? ''));
    return $base + [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'kostnad' => $sum,
        'kostnad_before' => jeab_ordrar_supplier_header_amount($row),
        'leverans' => $latest !== '' ? $latest : $headerDate,
        'leverans_before' => $headerDate,
        'lines' => $lines,
        'changed' => $changed ? 1 : 0,
        'written' => $written,
        'note' => $lines === [] ? 'inga leverantörsrader' : 'Aprilice lagras exkl. moms. Lightleap räknas om från inkl. moms (25 %) om inte moms=exkl.',
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_supplier_lines_recompute_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.supplier_lines_recompute', 'status' => 401, 'fortnox' => 'none', 'written' => 0];
    }
    $result = jeab_ordrar_supplier_lines_recompute($input);
    $result['status'] = !empty($result['ok']) ? 200 : ((string) ($result['error'] ?? '') === 'missing' ? 404 : 400);
    return $result;
}

/**
 * @param array{aprilice?:string,lightleap?:string,leverans?:string,bestalld_at?:string,kostnad?:string,supplier?:string,reference?:string,moms?:string,lines?:list<array<string,mixed>>} $facts
 */
function jeab_ordrar_apply_placed_shop(array $row, array $facts): array
{
    $lines = is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [];
    $linesTouched = false;
    foreach (['aprilice', 'lightleap'] as $shop) {
        $no = trim((string) ($facts[$shop] ?? ''));
        if ($no === '') {
            continue;
        }
        $key = $shop . '_order';
        if (trim((string) ($row[$key] ?? '')) === '') {
            $row[$key] = $no;
        }
        foreach ($lines as $i => $line) {
            if (!is_array($line) || !jeab_ordrar_line_shop_matches($line, $shop)) {
                continue;
            }
            if (trim((string) ($line['order_no'] ?? '')) !== '') {
                continue;
            }
            $lines[$i]['order_no'] = $no;
            $linesTouched = true;
        }
    }
    if ($linesTouched) {
        $row['inkop_lines'] = $lines;
    }
    $when = trim((string) ($facts['bestalld_at'] ?? ''));
    if ($when !== '' && trim((string) ($row['bestalld_at'] ?? '')) === '') {
        $row['bestalld_at'] = $when;
    }
    $preview = jeab_ordrar_supplier_merge_preview($row, $facts);
    if ($preview['incoming'] !== []) {
        $storedLines = jeab_ordrar_supplier_lines_of($row);
        if ($preview['lines'] !== $storedLines) {
            $row['supplier_lines'] = $preview['lines'];
        }
        $sum = jeab_ordrar_supplier_lines_sum($preview['lines']);
        if ($sum > 0 && jeab_ordrar_supplier_header_amount($row) !== $sum) {
            $row['bestall_kostnad'] = $sum;
        }
        $delivery = jeab_ordrar_supplier_latest($preview['lines']);
        if ($delivery !== '') {
            // Butikens datum skriver över planerat. Flera leverantörer: senaste datumet ligger kvar.
            $prev = trim((string) ($row['leverans'] ?? ''));
            if ($prev !== '' && $prev !== $delivery && trim((string) ($row['leverans_planerad'] ?? '')) === '') {
                $row['leverans_planerad'] = $prev;
            }
            if ($prev !== $delivery) {
                $row['leverans'] = $delivery;
            }
            if (trim((string) ($row['faktisk_leverans'] ?? '')) !== $delivery) {
                $row['faktisk_leverans'] = $delivery;
            }
            if ((string) ($row['leverans_source'] ?? '') !== 'shop') {
                $row['leverans_source'] = 'shop';
            }
        }
    }
    return $row;
}

function jeab_ordrar_shop_activity_text(string $shop, string $orderNo = ''): string
{
    $shop = jeab_ordrar_normalize_shop($shop);
    $orderNo = trim($orderNo);
    if ($orderNo !== '') {
        return ($shop === 'lightleap' ? 'Lightleap' : 'Aprilice') . ' ' . $orderNo;
    }
    return $shop === 'lightleap' ? 'Något är också på Lightleap' : 'Allt är på Aprilice';
}

function jeab_ordrar_append_shop_activity_row(array $row, string $text, string $key): array
{
    $text = trim($text);
    if ($text === '') {
        return $row;
    }
    foreach (jeab_ordrar_activity_list($row) as $item) {
        if (trim((string) ($item['text'] ?? '')) === $text) {
            return $row;
        }
    }
    return jeab_ordrar_activity_append($row, $text, '', $key, false);
}

function jeab_ordrar_append_shop_activity(array $row, array $input = []): array
{
    $numbered = [];
    $plain = [];
    foreach (jeab_ordrar_shops_used($row) as $shop) {
        $orderNo = jeab_ordrar_shop_order_no($row, $input, $shop);
        if ($orderNo !== '') {
            $numbered[$shop] = $orderNo;
        } else {
            $plain[] = $shop;
        }
    }
    if ($numbered === []) {
        return jeab_ordrar_append_shop_activity_row($row, jeab_ordrar_shop_label($row), 'shop:summary');
    }
    foreach ($numbered as $shop => $orderNo) {
        $row = jeab_ordrar_append_shop_activity_row($row, jeab_ordrar_shop_activity_text($shop, $orderNo), 'shop:' . $shop);
    }
    foreach ($plain as $shop) {
        $row = jeab_ordrar_append_shop_activity_row($row, jeab_ordrar_shop_activity_text($shop), 'shop:' . $shop);
    }
    return $row;
}

function jeab_ordrar_day(?string $raw): ?DateTimeImmutable
{
    $raw = trim((string) $raw);
    if ($raw === '' || !preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
        return null;
    }
    try {
        static $tz = null;
        if (!$tz instanceof DateTimeZone) {
            $tz = new DateTimeZone('Europe/Stockholm');
        }

        return new DateTimeImmutable($m[1] . ' 00:00:00', $tz);
    } catch (Exception) {
        return null;
    }
}

function jeab_ordrar_onskad_leverans(array $row, ?JeabStore $store = null): string
{
    $install = jeab_ordrar_day(jeab_ordrar_install_start($row, $store));
    if ($install instanceof DateTimeImmutable) {
        $wanted = jeab_ordrar_wanted_delivery_ymd($install->format('Y-m-d'), (string) ($row['kind'] ?? ''));
        if ($wanted !== '') {
            return $wanted;
        }
    }
    foreach (['onskad_leverans', 'önskad_leverans'] as $key) {
        $day = jeab_ordrar_day((string) ($row[$key] ?? ''));
        if ($day instanceof DateTimeImmutable) {
            return $day->format('Y-m-d');
        }
    }
    return '';
}

function jeab_ordrar_actual_delivery(array $row, array $input = []): string
{
    foreach (['leverans', 'faktisk_leverans', 'actual_delivery'] as $key) {
        $day = jeab_ordrar_day((string) ($input[$key] ?? ''));
        if ($day instanceof DateTimeImmutable) {
            return $day->format('Y-m-d');
        }
    }
    $fromLines = jeab_ordrar_supplier_latest(jeab_ordrar_supplier_lines_of($row));
    if ($fromLines !== '') {
        return $fromLines;
    }
    return jeab_ordrar_day(jeab_ordrar_aprilic_leverans($row))?->format('Y-m-d') ?? '';
}

function jeab_ordrar_bestall_qty_error(array $row): string
{
    $kind = function_exists('jeab_bokmalen_ordrar_webhook_kind')
        ? jeab_bokmalen_ordrar_webhook_kind($row)
        : '';
    if ($kind === '') {
        return '';
    }
    $lines = is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        if (function_exists('jeab_bokmalen_ordrar_line_qty_positive') && jeab_bokmalen_ordrar_line_qty_positive($line)) {
            return '';
        }
    }
    return 'Ingen rad har antal större än 0.';
}

function jeab_ordrar_stamp_line_shops(array $row): array
{
    $lines = is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [];
    foreach ($lines as $i => $line) {
        if (!is_array($line) || !jeab_ordrar_line_has_goods($line)) {
            continue;
        }
        if (jeab_ordrar_normalize_shop((string) ($line['shop'] ?? '')) === '') {
            $lines[$i]['shop'] = 'aprilice';
        }
    }
    $row['inkop_lines'] = $lines;
    $row['shop'] = jeab_ordrar_shop_summary($row) ?: 'aprilice';
    return $row;
}

function jeab_ordrar_persist_onskad(array $row, ?JeabStore $store = null): array
{
    $onskad = jeab_ordrar_onskad_leverans($row, $store);
    $row['onskad_leverans'] = $onskad;
    $row['önskad_leverans'] = $onskad;
    return $row;
}

function jeab_ordrar_move_booking_for_delivery(array $row, string $deliveryYmd, ?JeabStore $store = null): array
{
    $delivery = jeab_ordrar_day($deliveryYmd);
    $install = jeab_ordrar_day(jeab_ordrar_install_start($row, $store));
    if (!$delivery instanceof DateTimeImmutable || !$install instanceof DateTimeImmutable) {
        return $row;
    }
    $need = $delivery->modify('+7 days');
    if ($install->format('Y-m-d') === $need->format('Y-m-d')) {
        return $row;
    }
    $kind = jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '';
    $bookingId = (int) ($row['booking_id'] ?? 0);
    $booking = null;
    if ($bookingId > 0 && $store instanceof JeabStore) {
        try {
            $booking = $store->getBooking($bookingId);
        } catch (Throwable) {
            $booking = null;
        }
        if (!is_array($booking)) {
            $booking = null;
        }
    }
    if ($kind === 'sol') {
        $moveCompany = jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
        if ($moveCompany === 'malmo') {
            $pairDay = $need;
            $pair = null;
            for ($i = 0; $i < 14; $i++) {
                $pair = jeab_ordrar_meab_sol_pair_from_day($pairDay);
                if ($pair !== null) {
                    break;
                }
                $pairDay = $pairDay->modify('+1 day');
            }
            if ($pair === null) {
                return $row;
            }
            $starts = $pair['starts_at'];
            $ends = $pair['ends_at'];
        } else {
            $n = (int) $need->format('N');
            $monday = $n === 1 ? $need : $need->modify('+' . (8 - $n) . ' days');
            $starts = $monday->format('Y-m-d') . ' 00:00:00';
            $ends = $monday->modify('+5 days')->format('Y-m-d') . ' 00:00:00';
        }
    } else {
        $oldStart = is_array($booking) ? jeab_ordrar_day((string) ($booking['starts_at'] ?? '')) : $install;
        $oldEndRaw = is_array($booking) ? trim((string) ($booking['ends_at'] ?? '')) : '';
        $oldStartDt = null;
        $oldEndDt = null;
        if (is_array($booking)) {
            try {
                $oldStartDt = new DateTimeImmutable((string) ($booking['starts_at'] ?? ''), new DateTimeZone('Europe/Stockholm'));
                $oldEndDt = $oldEndRaw !== '' ? new DateTimeImmutable($oldEndRaw, new DateTimeZone('Europe/Stockholm')) : null;
            } catch (Exception) {
                $oldStartDt = null;
                $oldEndDt = null;
            }
        }
        $days = (int) $install->diff($need)->format('%r%a');
        if ($oldStartDt instanceof DateTimeImmutable) {
            $newStart = $oldStartDt->modify('+' . $days . ' days');
            $newEnd = $oldEndDt instanceof DateTimeImmutable
                ? $oldEndDt->modify('+' . $days . ' days')
                : $newStart->modify('+1 day');
        } else {
            $clock = $kind === 'laddbox' ? ['07:00:00', '12:00:00'] : ['07:00:00', '16:00:00'];
            $newStart = $need->setTime((int) substr($clock[0], 0, 2), (int) substr($clock[0], 3, 2));
            $newEnd = $need->setTime((int) substr($clock[1], 0, 2), (int) substr($clock[1], 3, 2));
        }
        if ((int) $newStart->format('N') >= 6) {
            $shift = 8 - (int) $newStart->format('N');
            $newStart = $newStart->modify('+' . $shift . ' days');
            $newEnd = $newEnd->modify('+' . $shift . ' days');
        }
        $starts = $newStart->format('Y-m-d H:i:s');
        $ends = $newEnd->format('Y-m-d H:i:s');
        unset($oldStart);
    }
    if ($bookingId > 0 && $store instanceof JeabStore && is_array($booking)) {
        $saved = $store->saveBooking($bookingId, [
            'starts_at' => $starts,
            'ends_at' => $ends,
            'updated_at' => jeab_now(),
        ]);
        if (is_array($saved) && function_exists('jeab_calendar_sync_outlook')) {
            try {
                jeab_calendar_sync_outlook($store, $saved);
            } catch (Throwable $e) {
                if (function_exists('jeab_calendar_sync_outlook_log')) {
                    jeab_calendar_sync_outlook_log($e);
                }
            }
        }
    }
    if (!is_array($row['steps'] ?? null)) {
        $row['steps'] = [];
    }
    $row['steps']['bokad_tid'] = $starts;
    if ($bookingId > 0) {
        $row['booking_id'] = $bookingId;
    }
    $deliveryFile = __DIR__ . '/fortnox_leveransdatum.php';
    if (is_file($deliveryFile)) {
        require_once $deliveryFile;
    }
    if (function_exists('jeab_fortnox_leveransdatum_push')) {
        $deliveryResult = null;
        try {
            $deliveryResult = jeab_fortnox_leveransdatum_push($row, true);
        } catch (Throwable $e) {
            $deliveryResult = $e;
        }
        $row = jeab_ordrar_remember_leveransdatum($row, $deliveryResult);
    }
    return $row;
}

function jeab_ordrar_normalize_foranmalan_status(string $raw): string
{
    $raw = strtoupper(trim($raw));
    return in_array($raw, ['A', 'B', 'C'], true) ? $raw : '';
}

function jeab_ordrar_foranmalan_status(array $row): string
{
    return jeab_ordrar_normalize_foranmalan_status((string) ($row['foranmalan_status'] ?? ''));
}

function jeab_ordrar_touch_foranmalan_draft(array $row): array
{
    $existing = trim((string) ($row['foranmalan_draft'] ?? $row['foranmalan_draft_at'] ?? ''));
    if ($existing === '') {
        $today = jeab_ordrar_today();
        $row['foranmalan_draft'] = $today;
        $row['foranmalan_draft_at'] = $today;
    }
    return $row;
}

function jeab_ordrar_apply_foranmalan_status(array $row, array $input): array
{
    if (array_key_exists('foranmalan_status', $input)) {
        $row['foranmalan_status'] = jeab_ordrar_normalize_foranmalan_status((string) $input['foranmalan_status']);
    }
    return $row;
}

function jeab_ordrar_foranmalan_date_raw(array $row): string
{
    $draft = trim((string) ($row['foranmalan_draft'] ?? $row['foranmalan_draft_at'] ?? ''));
    if ($draft !== '') {
        return $draft;
    }
    $sent = trim((string) ((is_array($row['steps'] ?? null) ? $row['steps'] : [])['foranmald'] ?? ''));
    if ($sent !== '') {
        return $sent;
    }
    if (!jeab_ordrar_foranmalan_saved($row)) {
        return '';
    }
    $updated = trim((string) ($row['updated_at'] ?? ''));
    if ($updated !== '') {
        return $updated;
    }
    return trim((string) ($row['created_at'] ?? ''));
}

function jeab_ordrar_foranmalan_step_date(array $row): string
{
    $draft = jeab_ordrar_mmdd(jeab_ordrar_foranmalan_date_raw($row));
    $case = jeab_ordrar_foranmalan_case_label($row);
    if ($case !== '') {
        return $draft !== '' ? $draft . ' ' . $case : $case;
    }
    if ($draft === '') {
        return '';
    }
    $status = jeab_ordrar_foranmalan_status($row);
    return $status === '' ? $draft : $draft . ' ' . $status;
}

function jeab_ordrar_bestall_step_date(array $row, ?JeabStore $store = null): string
{
    $actual = jeab_ordrar_actual_delivery($row);
    $onskad = jeab_ordrar_onskad_leverans($row, $store);
    $raw = $actual !== '' ? $actual : $onskad;
    if ($raw === '') {
        $raw = (string) ((is_array($row['steps'] ?? null) ? $row['steps'] : [])['bestalld'] ?? '');
    }
    return jeab_ordrar_mmdd($raw);
}

function jeab_ordrar_save_foranmalan_status(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $row = jeab_ordrar_touch_foranmalan_draft($row);
    $row = jeab_ordrar_apply_foranmalan_status($row, $input);
    $status = jeab_ordrar_foranmalan_status($row);
    // Utkast och Manuellt räknas som klart steg (spec). Automatiskt väntar på Bokmalen.
    // foranmald och bestalld stämplas inte här.
    if ($status === 'B' || $status === 'C') {
        $row = jeab_ordrar_stamp_today($row, 'spec');
    }
    $row['foranmalan_saved'] = true;
    $row['updated_at'] = jeab_now();
    return jeab_purchase_put($row);
}

/**
 * Nästa på Föranmälan (eon).
 * Ellevio startar fullmakt via Bokmalen. Övriga nätägare postar ordrar.foranmalan med utkast:false.
 */
function jeab_ordrar_foranmalan_chosen_natbolag(array $row): string
{
    // Nästa ska skicka det nätbolag som redan ligger på ordern.
    // Adresslookup får inte skriva över ett valt Vattenfall med E.ON.
    $chosen = trim((string) ($row['natbolag'] ?? ''));
    if (in_array($chosen, jeab_ordrar_natbolag_options(), true)) {
        return $chosen;
    }
    return jeab_ordrar_natbolag($row);
}

function jeab_ordrar_foranmalan_nasta(?JeabStore $store, array $row): array
{
    if (function_exists('jeab_ellevio_fullmakt_applies') && jeab_ellevio_fullmakt_applies($row)) {
        if (!function_exists('jeab_ellevio_fullmakt_start')) {
            throw new InvalidArgumentException('Fullmaktsmodulen saknas.');
        }
        $row = jeab_ellevio_fullmakt_start($row, $store);
        if (!jeab_ordrar_foranmalan_saved($row)) {
            $row = jeab_ordrar_touch_foranmalan_draft($row);
            $row['foranmalan_saved'] = true;
            $row['updated_at'] = jeab_now();
            $row = jeab_purchase_put($row);
        }
        return $row;
    }
    if (function_exists('jeab_seom_fullmakt_applies') && jeab_seom_fullmakt_applies($row)) {
        $status = jeab_ordrar_foranmalan_status($row);
        if ($status === 'B' || $status === 'C') {
            $row = jeab_ordrar_touch_foranmalan_draft($row);
            $row['foranmalan_saved'] = true;
            $row['updated_at'] = jeab_now();
            return jeab_purchase_put($row);
        }
        if (!function_exists('jeab_seom_fullmakt_start')) {
            throw new InvalidArgumentException('SEOM-blanketten saknas.');
        }
        return jeab_seom_fullmakt_start($row);
    }
    $orderId = (int) ($row['reminder_id'] ?? 0);
    if ($orderId <= 0) {
        $orderId = (int) ($row['id'] ?? 0);
    }
    return jeab_ordrar_save_foranmalan([
        'id' => $orderId,
        'intent' => 'send',
        'natbolag' => jeab_ordrar_foranmalan_chosen_natbolag($row),
    ]);
}

function jeab_ordrar_bokmalen_order_id(array $row): int
{
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        $id = (int) ($row['id'] ?? 0);
    }
    return $id;
}

/** Live-skick: föranmälan är stämplad, eller beställning har steps.bestalld. */
function jeab_ordrar_bokmalen_live_done(string $type, array $row): bool
{
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    if ($type === 'ordrar.foranmalan') {
        if (trim((string) ($steps['foranmald'] ?? '')) !== '') {
            return true;
        }
        return jeab_ordrar_foranmalan_case_status($row) === 'skickad';
    }
    if ($type === 'ordrar.bestall') {
        return trim((string) ($steps['bestalld'] ?? '')) !== '';
    }
    if ($type === 'ordrar.plombbrytning') {
        return trim((string) ($row['plombbrytning_sent_at'] ?? '')) !== '';
    }
    return false;
}

function jeab_ordrar_bokmalen_empty_facts(): array
{
    return ['aprilice' => '', 'lightleap' => '', 'leverans' => '', 'bestalld_at' => ''];
}

/** Kopiera redan sparad sändning så ett senare sparande inte suddar stämpeln. */
function jeab_ordrar_bokmalen_merge_kept(string $type, array $working, array $fresh): array
{
    $freshSteps = is_array($fresh['steps'] ?? null) ? $fresh['steps'] : [];
    if (!is_array($working['steps'] ?? null)) {
        $working['steps'] = [];
    }
    if ($type === 'ordrar.foranmalan') {
        $sent = trim((string) ($freshSteps['foranmald'] ?? ''));
        if ($sent !== '' && trim((string) ($working['steps']['foranmald'] ?? '')) === '') {
            $working['steps']['foranmald'] = $sent;
        }
        if (jeab_ordrar_foranmalan_case_status($working) === '' && jeab_ordrar_foranmalan_case_status($fresh) !== '') {
            $working['foranmalan_case_status'] = jeab_ordrar_foranmalan_case_status($fresh);
        }
    } elseif ($type === 'ordrar.bestall') {
        $sent = trim((string) ($freshSteps['bestalld'] ?? ''));
        if ($sent !== '' && trim((string) ($working['steps']['bestalld'] ?? '')) === '') {
            $working['steps']['bestalld'] = $sent;
        }
        foreach (['bestalld_at', 'aprilice_order', 'lightleap_order'] as $key) {
            if (trim((string) ($working[$key] ?? '')) === '' && trim((string) ($fresh[$key] ?? '')) !== '') {
                $working[$key] = $fresh[$key];
            }
        }
    } elseif ($type === 'ordrar.plombbrytning') {
        $sent = trim((string) ($fresh['plombbrytning_sent_at'] ?? ''));
        if ($sent !== '' && trim((string) ($working['plombbrytning_sent_at'] ?? '')) === '') {
            $working['plombbrytning_sent_at'] = $sent;
            $working['plombbrytning_sent'] = true;
        }
    }
    $have = [];
    $list = jeab_ordrar_activity_list($working);
    foreach ($list as $item) {
        $key = trim((string) ($item['key'] ?? ''));
        if ($key !== '') {
            $have[$key] = true;
        }
    }
    foreach (jeab_ordrar_activity_list($fresh) as $item) {
        $key = trim((string) ($item['key'] ?? ''));
        if ($key === '' || isset($have[$key])) {
            continue;
        }
        $list[] = $item;
        $have[$key] = true;
    }
    $working['activity'] = $list;
    $working['log'] = $list;
    return $working;
}

function jeab_ordrar_bokmalen_stamp_live(string $type, array $row): array
{
    if (!is_array($row['steps'] ?? null)) {
        $row['steps'] = [];
    }
    if ($type === 'ordrar.foranmalan') {
        if (trim((string) ($row['steps']['foranmald'] ?? '')) === '') {
            $row['steps']['foranmald'] = jeab_ordrar_today();
        }
        if (jeab_ordrar_foranmalan_status($row) === '') {
            $row['foranmalan_status'] = 'A';
        }
        if (jeab_ordrar_foranmalan_case_status($row) === '') {
            $row['foranmalan_case_status'] = 'skickad';
        }
        return $row;
    }
    if ($type === 'ordrar.bestall') {
        $row = jeab_ordrar_stamp_today($row, 'bestalld');
        return $row;
    }
    if ($type === 'ordrar.plombbrytning') {
        $now = jeab_now();
        $row['plombbrytning_sent'] = true;
        if (trim((string) ($row['plombbrytning_sent_at'] ?? '')) === '') {
            $row['plombbrytning_sent_at'] = $now;
        }
        return jeab_ordrar_activity_append($row, 'Plombbrytning skickad till Bokmalen', $now, 'plombbrytning:' . $now, false);
    }
    return $row;
}

/**
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function jeab_ordrar_with_order_lock(int $orderId, callable $fn): mixed
{
    if ($orderId <= 0) {
        return $fn();
    }
    $dir = (function_exists('jeab_data_dir') ? jeab_data_dir() : sys_get_temp_dir()) . '/locks';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return $fn();
    }
    $fh = @fopen($dir . '/bokmalen-' . $orderId . '.lock', 'c');
    if ($fh === false) {
        return $fn();
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return $fn();
        }
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Synlig status när föranmälan sparats men Bokmalen inte tog emot POST:en. */
function jeab_ordrar_foranmalan_unsent_status(): string
{
    return 'ej skickad till Bokmalen';
}

/** Meningen användaren får se. Inget ord «secret», så jeab_public_error_sentence släpper igenom den. */
function jeab_ordrar_foranmalan_send_fail_message(): string
{
    return 'Föranmälan kunde inte skickas till Bokmalen. Kontrollera webhook-nyckeln och försök igen.';
}

function jeab_ordrar_foranmalan_send_failed(array $row): bool
{
    return trim((string) ($row['foranmalan_case_status'] ?? '')) === jeab_ordrar_foranmalan_unsent_status();
}

/**
 * En väg till Bokmalen för beställning, föranmälan och plombbrytning.
 * Sol och batteri postar föranmälan och beställning. Laddbox och okänd
 * produkt postar inte dem (ingen webshop, ingen föranmälan).
 * Plombbrytning (ordrar.plombbrytning) postar för sol, batteri, laddbox
 * och servicejobb, med egen payload i notify. Järfälla och Malmö delar vägen;
 * bolag är bara ett fält på ordern.
 * En lyckad live-post (utkast false) stämplas under lås. Nästa klick
 * av samma typ postar inte igen.
 *
 * @return array{deliver:string,facts:?array,row:array}
 */
function jeab_ordrar_bokmalen_once(string $type, array $row, bool $utkast, bool $resend = false): array
{
    $plomb = $type === 'ordrar.plombbrytning';
    $kind = function_exists('jeab_bokmalen_ordrar_webhook_kind')
        ? jeab_bokmalen_ordrar_webhook_kind($row)
        : '';
    if (!$plomb && $kind === '') {
        return ['deliver' => 'skip', 'facts' => null, 'row' => $row];
    }
    if ($plomb && function_exists('jeab_bokmalen_ordrar_product_label') && jeab_bokmalen_ordrar_product_label($row) === '') {
        return ['deliver' => 'skip', 'facts' => null, 'row' => $row];
    }
    $id = jeab_ordrar_bokmalen_order_id($row);
    return jeab_ordrar_with_order_lock($id, static function () use ($type, $row, $utkast, $id, $resend, $plomb): array {
        if ($resend) {
            // «Skicka igen»: samma post som första gången, förbi once-spärren.
            // Andra klicket väntar på låset och ser då att det nyss skickades.
            $fresh = $id > 0 && function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
            $row = is_array($fresh) ? $fresh : $row;
            if (jeab_ordrar_foranmalan_resent_recently($row)) {
                return ['deliver' => 'recent', 'facts' => null, 'row' => $row];
            }
        } elseif ($id > 0 && function_exists('jeab_purchase_get')) {
            $fresh = jeab_purchase_get($id);
            if ($plomb && is_array($fresh) && jeab_ordrar_allt_klart($fresh)) {
                return ['deliver' => 'skip', 'facts' => null, 'row' => $fresh];
            }
            if (is_array($fresh) && jeab_ordrar_bokmalen_live_done($type, $fresh)) {
                return [
                    'deliver' => 'kept',
                    'facts' => jeab_ordrar_bokmalen_empty_facts(),
                    'row' => jeab_ordrar_bokmalen_merge_kept($type, $row, $fresh),
                ];
            }
        }
        if (!$resend && jeab_ordrar_bokmalen_live_done($type, $row)) {
            return ['deliver' => 'kept', 'facts' => jeab_ordrar_bokmalen_empty_facts(), 'row' => $row];
        }
        if (!function_exists('jeab_bokmalen_ordrar_notify')) {
            return ['deliver' => 'failed', 'facts' => null, 'row' => $row];
        }
        $facts = jeab_bokmalen_ordrar_notify($type, $row, $utkast, $resend ? ['resend' => true] : []);
        if (!is_array($facts)) {
            return ['deliver' => 'failed', 'facts' => null, 'row' => $row];
        }
        if ($resend) {
            $now = jeab_now();
            $row['foranmalan_resent_at'] = $now;
            $row['foranmalan_resent_count'] = (int) ($row['foranmalan_resent_count'] ?? 0) + 1;
            // Ny anmälan: läget börjar om som Skickad (t.ex. efter Nekad).
            $row['foranmalan_case_status'] = 'skickad';
            $row = jeab_ordrar_activity_append($row, 'Föranmälan skickad igen', $now, 'foranmald_igen:' . $now, false);
            $row['updated_at'] = $now;
            if ($id > 0 && function_exists('jeab_purchase_put')) {
                $row = jeab_purchase_put($row);
            }
        } elseif (!$utkast) {
            $row = jeab_ordrar_bokmalen_stamp_live($type, $row);
            if ($id > 0 && function_exists('jeab_purchase_put')) {
                $row['updated_at'] = jeab_now();
                $row = jeab_purchase_put($row);
            }
        }
        return ['deliver' => 'sent', 'facts' => $facts, 'row' => $row];
    });
}

function jeab_ordrar_save_foranmalan(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    if (function_exists('jeab_ellevio_fullmakt_pull_signed')) {
        $row = jeab_ellevio_fullmakt_pull_signed($row, false);
    }
    $nat = trim((string) ($input['natbolag'] ?? ''));
    if (!in_array($nat, jeab_ordrar_natbolag_options(), true)) {
        $nat = jeab_ordrar_natbolag($row);
    }
    $row['natbolag'] = $nat;
    $storedAid = jeab_ordrar_anlaggnings_id_digits((string) ($row['anlaggnings_id'] ?? ''));
    if (array_key_exists('anlaggnings_id', $input)) {
        $storedAid = jeab_ordrar_anlaggnings_id_digits((string) $input['anlaggnings_id']);
    }
    $row['anlaggnings_id'] = $storedAid;
    $row = jeab_ordrar_touch_foranmalan_draft($row);
    $row = jeab_ordrar_apply_foranmalan_status($row, $input);
    $send = !jeab_ordrar_is_utkast_post($input);
    if (trim((string) ($input['intent'] ?? '')) === 'send') {
        $send = true;
    }
    $missingBm = function_exists('jeab_ordrar_bokmalen_missing')
        ? jeab_ordrar_bokmalen_missing($row, 'ordrar.foranmalan')
        : [];
    // Samma produktregel som jeab_ordrar_bokmalen_once: bara sol och batteri föranmäls.
    $delivers = !function_exists('jeab_bokmalen_ordrar_webhook_kind')
        || jeab_bokmalen_ordrar_webhook_kind($row) !== '';
    if ($send && $delivers && $missingBm !== []) {
        $row['updated_at'] = jeab_now();
        jeab_purchase_put($row);
        throw new InvalidArgumentException('Saknas för Bokmalen: ' . implode(', ', $missingBm) . '.');
    }
    if ($send && $delivers && !jeab_ordrar_bokmalen_skips_anlaggnings_id($row)) {
        $aidLen = strlen(jeab_ordrar_anlaggnings_id_digits((string) ($row['anlaggnings_id'] ?? '')));
        if ($aidLen !== 18) {
            $row['updated_at'] = jeab_now();
            jeab_purchase_put($row);
            throw new InvalidArgumentException($aidLen === 0
                ? 'Anläggnings-id krävs för att föranmäla.'
                : 'Anläggnings-id ska vara 18 siffror.');
        }
    }
    $row['foranmalan_saved'] = true;
    // Skickad föranmälan: POST till Bokmalen måste lyckas innan vi stämplar.
    // Annars ser UI ut att gå vidare medan Bokmalen aldrig vaknar.
    // Andra klicket går genom samma once-väg och postar inte en ny föranmälan.
    $deliver = 'sent';
    if ($send && $missingBm === []) {
        if (!function_exists('jeab_ordrar_bokmalen_once')) {
            throw new InvalidArgumentException('Bokmalen-webhook saknas på servern.');
        }
        $once = jeab_ordrar_bokmalen_once('ordrar.foranmalan', $row, false);
        $row = is_array($once['row'] ?? null) ? $once['row'] : $row;
        $deliver = (string) ($once['deliver'] ?? 'failed');
        if ($deliver === 'failed') {
            // Formuläret ska ligga kvar. Steget är inte skickat, så «Skicka igen»
            // går genom samma once-väg (ingen resend, ingen automatisk omsändning).
            $row['foranmalan_saved'] = true;
            $row['foranmalan_case_status'] = jeab_ordrar_foranmalan_unsent_status();
            $row['updated_at'] = jeab_now();
            jeab_purchase_put($row);
            throw new InvalidArgumentException(jeab_ordrar_foranmalan_send_fail_message());
        }
    } elseif (!$send && $missingBm === [] && function_exists('jeab_ordrar_bokmalen_once')) {
        // Utkast får utkast:true. Misslyckad utkast-POST stoppar inte sparandet.
        $once = jeab_ordrar_bokmalen_once('ordrar.foranmalan', $row, true);
        $deliver = (string) ($once['deliver'] ?? 'sent');
        if ($deliver !== 'failed' && is_array($once['row'] ?? null)) {
            $row = $once['row'];
        }
    }
    if ($deliver === 'skip') {
        $stored = jeab_ordrar_bokmalen_order_id($row) > 0 ? jeab_purchase_get(jeab_ordrar_bokmalen_order_id($row)) : null;
        return is_array($stored) ? $stored : $row;
    }
    if ($send && ($deliver === 'sent' || $deliver === 'kept')) {
        if (!is_array($row['steps'] ?? null)) {
            $row['steps'] = [];
        }
        if (trim((string) ($row['steps']['foranmald'] ?? '')) === '') {
            $row['steps']['foranmald'] = jeab_ordrar_today();
        }
        if (jeab_ordrar_foranmalan_status($row) === '') {
            $row['foranmalan_status'] = 'A';
        }
        if (jeab_ordrar_foranmalan_case_status($row) === '') {
            $row['foranmalan_case_status'] = 'skickad';
        }
        $row = jeab_ordrar_activity_append($row, 'Föranmälan skickad', (string) $row['steps']['foranmald'], 'foranmald', false);
    }
    $row['updated_at'] = jeab_now();
    return jeab_purchase_put($row);
}

/**
 * «Skicka igen» på en redan skickad föranmälan (övriga nätägare).
 * Samma väg som första skickningen (jeab_ordrar_bokmalen_once 'ordrar.foranmalan',
 * utkast false, under orderlåset) men med resend=true förbi once-spärren, som annars svarar «kept»
 * och inte postar något. Markeras resend:true i payloaden så Bokmalen vet att det är avsiktligt.
 * Ellevio går via fullmakten (ordrar.fullmakt_resend). Spärr mot dubbelklick: en minut.
 */
function jeab_ordrar_foranmalan_resend(array $input): array
{
    $id = (int) ($input['id'] ?? 0);
    $row = jeab_ordrar_require($id);
    if (!jeab_ordrar_is_solbatteri($row)) {
        throw new InvalidArgumentException('Föranmälan finns bara för batteri och sol.');
    }
    if (jeab_ordrar_allt_klart($row)) {
        throw new InvalidArgumentException('Ärendet är stängt. Föranmälan skickas inte igen.');
    }
    if (function_exists('jeab_seom_fullmakt_applies') && jeab_seom_fullmakt_applies($row)) {
        if (!function_exists('jeab_seom_fullmakt_resend')) {
            throw new InvalidArgumentException('SEOM-blanketten saknas.');
        }
        return jeab_seom_fullmakt_resend($row, $input);
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    if (trim((string) ($steps['foranmald'] ?? '')) === '') {
        throw new InvalidArgumentException('Föranmälan är inte skickad än. Använd Skicka föranmälan.');
    }
    if (function_exists('jeab_ellevio_fullmakt_applies') && jeab_ellevio_fullmakt_applies($row)) {
        throw new InvalidArgumentException('Ellevio går via fullmakten. Föranmälan skickas av Bokmalen efter signering.');
    }
    if (!jeab_ordrar_foranmalan_can_resend($row)) {
        throw new InvalidArgumentException('Föranmälan är inte Automatiskt via Bokmalen. Inget skickas igen.');
    }
    $missing = function_exists('jeab_ordrar_bokmalen_missing') ? jeab_ordrar_bokmalen_missing($row, 'ordrar.foranmalan') : [];
    if ($missing !== []) {
        throw new InvalidArgumentException('Saknas för Bokmalen: ' . implode(', ', $missing) . '.');
    }
    $res = jeab_ordrar_bokmalen_once('ordrar.foranmalan', $row, false, true);
    $deliver = (string) ($res['deliver'] ?? '');
    if ($deliver === 'recent') {
        throw new InvalidArgumentException('Föranmälan skickades igen nyss. Vänta en minut innan du försöker igen.');
    }
    if ($deliver !== 'sent') {
        throw new InvalidArgumentException('Föranmälan kunde inte skickas till Bokmalen. Kontrollera webhook-secret och försök igen.');
    }
    return is_array($res['row'] ?? null) ? $res['row'] : $row;
}

/**
 * Bekräftad plombbrytning går genom jeab_ordrar_bokmalen_once (type ordrar.plombbrytning),
 * samma postställe som föranmälan och beställning. Sent-flaggan är tidsstämpeln.
 * Utan bekräftelse (plombbrytning=1) postas inget. En sparad tidsstämpel gör nästa
 * anrop idempotent: samma status tillbaka, ingen ny webhook. Stängda ärenden
 * utan stämpel och bokade tider rörs inte.
 *
 * @return array<string,mixed>
 */
function jeab_ordrar_plombbrytning_save(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $want = (string) ($input['plombbrytning'] ?? '') === '1';
    $sentAt = trim((string) ($row['plombbrytning_sent_at'] ?? ''));
    if ($sentAt !== '') {
        $row['plombbrytning_sent'] = true;
        $row['_plomb_deliver'] = 'kept';
        return $row;
    }
    if (jeab_ordrar_allt_klart($row)) {
        if ($want) {
            throw new InvalidArgumentException('Ärendet är stängt. Plombbrytning skickas inte.');
        }
        $row['_plomb_deliver'] = 'closed';
        return $row;
    }
    if (!$want) {
        $row['_plomb_deliver'] = 'idle';
        return $row;
    }
    $once = jeab_ordrar_bokmalen_once('ordrar.plombbrytning', $row, false);
    $saved = is_array($once['row'] ?? null) ? $once['row'] : $row;
    $deliver = (string) ($once['deliver'] ?? 'failed');
    if ($deliver === 'skip' && jeab_ordrar_allt_klart($saved)) {
        throw new InvalidArgumentException('Ärendet är stängt. Plombbrytning skickas inte.');
    }
    if ($deliver === 'failed' || $deliver === 'skip') {
        throw new InvalidArgumentException('Plombbrytning kunde inte skickas till Bokmalen. Kontrollera webhook-secret och försök igen.');
    }
    $saved['_plomb_deliver'] = $deliver === 'sent' ? 'sent' : 'kept';
    return $saved;
}

/**
 * Visas «Skicka igen» för föranmälan? Samma regel i gamla och nya vyn:
 * skickad (steps.foranmald), batteri/sol, inte stängd, inte Ellevio (fullmakten).
 */
function jeab_ordrar_foranmalan_can_resend(array $row): bool
{
    if (!jeab_ordrar_is_solbatteri($row) || jeab_ordrar_allt_klart($row)) {
        return false;
    }
    $mode = jeab_ordrar_foranmalan_status($row);
    if ($mode !== '' && $mode !== 'A') {
        return false;
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    if (trim((string) ($steps['foranmald'] ?? '')) === '') {
        return false;
    }
    return !(function_exists('jeab_ellevio_fullmakt_applies') && jeab_ellevio_fullmakt_applies($row));
}

function jeab_ordrar_foranmalan_resent_recently(array $row, int $seconds = 60): bool
{
    $at = trim((string) ($row['foranmalan_resent_at'] ?? ''));
    if ($at === '') {
        return false;
    }
    $ts = strtotime($at);
    $now = strtotime(jeab_now());
    return $ts !== false && $now !== false && ($now - $ts) < $seconds && ($now - $ts) >= -5;
}

function jeab_ordrar_save_orderrader(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $prev = is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [];
    $lines = jeab_ordrar_inkop_from_input($input);
    foreach ($lines as $i => $line) {
        if (jeab_ordrar_normalize_shop((string) ($line['shop'] ?? '')) !== '') {
            continue;
        }
        $old = is_array($prev[$i] ?? null) ? $prev[$i] : [];
        $keep = jeab_ordrar_normalize_shop((string) ($old['shop'] ?? ''));
        if ($keep !== '') {
            $lines[$i]['shop'] = $keep;
        }
    }
    $row['inkop_lines'] = $lines;
    $row['orderrader_saved'] = true;
    $row = jeab_ordrar_stamp_today($row, 'orderrader');
    if (trim((string) ($row['orderrader_saved_at'] ?? '')) === '') {
        $row['orderrader_saved_at'] = jeab_ordrar_today();
    }
    // Solkoll körs inte här: autosparet går på varje tangent och fick skapa dubbletter.
    // Knappen «Skapa i Solkoll» (ordrar.solkoll) skapar/kopplar med lås och idempotens.
    $row['updated_at'] = jeab_now();
    return jeab_purchase_put($row);
}

function jeab_ordrar_bestall_take_lines(array $input): void
{
    // Beställ skickar med inköpslistan som den står på skärmen (line_*), så att
    // antalen alltid följer med även om autosparet inte hunnit/lyckats (t.ex. direkt efter mall).
    if (is_array($input['line_qty'] ?? null) && is_array($input['line_sku'] ?? null)) {
        $posted = jeab_ordrar_inkop_from_input($input);
        $hasQty = false;
        foreach ($posted as $line) {
            if (is_array($line) && function_exists('jeab_bokmalen_ordrar_line_qty_positive') && jeab_bokmalen_ordrar_line_qty_positive($line)) {
                $hasQty = true;
                break;
            }
        }
        if ($hasQty) {
            jeab_ordrar_save_orderrader($input);
        }
    }
}

function jeab_ordrar_save_bestall(array $input): array
{
    jeab_ordrar_bestall_take_lines($input);
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    if (function_exists('jeab_ellevio_fullmakt_pull_signed')) {
        $row = jeab_ellevio_fullmakt_pull_signed($row, false);
    }
    $store = null;
    try {
        $maybe = jeab_store();
        $store = $maybe instanceof JeabStore ? $maybe : null;
    } catch (Throwable) {
        $store = null;
    }
    $place = !jeab_ordrar_is_utkast_post($input);
    if (trim((string) ($input['intent'] ?? '')) === 'bestall') {
        $place = true;
    }
    if ($place && $store instanceof JeabStore && jeab_ordrar_books_at_bestall($row) && !jeab_ordrar_is_booked($row, $store)) {
        $input = jeab_ordrar_proposal_book_input($store, $row, $input) + $input;
    }
    $missingBm = function_exists('jeab_ordrar_bokmalen_missing')
        ? jeab_ordrar_bokmalen_missing($row, 'ordrar.bestall')
        : [];
    // Nät, anläggnings-id och kWh hör till föranmälan, inte shop.
    // missing() är tom för ordrar.bestall så webhooken går ändå.
    // Utkast postas med utkast:true; automatisk beställ med utkast:false.
    // Antal kopieras inte från mall eller annan order. 0 och blank beställs inte.
    if ($place) {
        $qtyError = jeab_ordrar_bestall_qty_error($row);
        if ($qtyError !== '') {
            throw new InvalidArgumentException($qtyError);
        }
    }
    $row['bestall_draft'] = true;
    $delivery = jeab_ordrar_actual_delivery($row, $input);
    if ($delivery !== '') {
        $row['leverans'] = $delivery;
        $row['faktisk_leverans'] = $delivery;
    }
    $alreadyBestalld = jeab_ordrar_bestalld($row);
    if ($place && !$alreadyBestalld) {
        $row = jeab_ordrar_stamp_line_shops($row);
    }
    // Redan bokad tid flyttas inte när hårdvaran beställs.
    $row = jeab_ordrar_persist_onskad($row, $store);
    $row['updated_at'] = jeab_now();
    $row = jeab_purchase_put($row);
    $facts = null;
    $deliver = 'sent';
    if ($missingBm === []) {
        // Samma once-väg som föranmälan. Andra Nästa postar inte en ny shoporder.
        $once = jeab_ordrar_bokmalen_once('ordrar.bestall', $row, !$place);
        $row = is_array($once['row'] ?? null) ? $once['row'] : $row;
        $deliver = (string) ($once['deliver'] ?? 'failed');
        $facts = $once['facts'] ?? null;
    }
    if ($deliver === 'skip') {
        if ($place && jeab_ordrar_bestalld($row) && function_exists('jeab_ordrar_solkoll_after_placed')) {
            $row = jeab_ordrar_solkoll_after_placed($row);
        }
        return $row;
    }
    if ($place && $deliver === 'failed') {
        throw new InvalidArgumentException('Beställningen gick inte fram till Bokmalen.');
    }
    $beforeShop = $row;
    if ($place && $deliver === 'sent') {
        if (!jeab_ordrar_bestalld($row)) {
            $row = jeab_ordrar_stamp_today($row, 'bestalld');
        }
        if (is_array($facts)) {
            $row = jeab_ordrar_apply_placed_shop($row, $facts);
        }
        if (trim((string) ($row['bestalld_at'] ?? '')) === '') {
            $row['bestalld_at'] = jeab_now();
        }
        $row = jeab_ordrar_append_shop_activity($row, $input);
    }
    if ($row !== $beforeShop) {
        $row['updated_at'] = jeab_now();
        $row = jeab_purchase_put($row);
    }
    if ($place && jeab_ordrar_is_solbatteri($row) && $store instanceof JeabStore && !jeab_ordrar_is_booked($row, $store) && (string) ($input['book_confirmed'] ?? '') === '1') {
        $row = jeab_ordrar_create_booking($store, [
            'id' => (int) ($row['reminder_id'] ?? $input['id'] ?? 0),
            'install_date' => (string) ($input['install_date'] ?? $row['install_date'] ?? ''),
            'install_time' => (string) ($input['install_time'] ?? $row['install_time'] ?? ''),
            'keep_slot' => (string) ($input['keep_slot'] ?? ''),
            'proposal_starts' => (string) ($input['proposal_starts'] ?? ''),
            'proposal_ends' => (string) ($input['proposal_ends'] ?? ''),
        ]);
    }
    if ($place && jeab_ordrar_bestalld($row) && function_exists('jeab_ordrar_solkoll_after_placed')) {
        $row = jeab_ordrar_solkoll_after_placed($row);
    }
    return $row;
}


function jeab_ordrar_save_boka_bestall(JeabStore $store, array $input): array
{
    jeab_ordrar_bestall_take_lines($input);
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $row = jeab_ordrar_apply_bestallning_status($row, $input);
    if (jeab_ordrar_bestallning_status($row) === '') {
        $row['bestallning_status'] = 'A';
    }
    $proposal = jeab_ordrar_install_proposal($store, $row);
    $install = jeab_ordrar_ymd((string) ($proposal['starts_at'] ?? ''));
    if ($install === '') {
        $install = jeab_ordrar_suggest_install_ymd($row, $store);
    }
    if ($install !== '') {
        $row['install_date'] = $install;
    }
    $delivery = (string) ($proposal['delivery'] ?? '');
    if ($delivery === '') {
        $delivery = jeab_ordrar_suggest_delivery_ymd($row, $store, $install);
    }
    if ($delivery !== '') {
        $row['onskad_leverans'] = $delivery;
        $row['önskad_leverans'] = $delivery;
        $row['leverans'] = $delivery;
        $row['faktisk_leverans'] = $delivery;
    }
    $status = jeab_ordrar_bestallning_status($row);
    $advance = jeab_ordrar_wants_advance($input);
    $bookInput = [];
    if ($advance && $status !== 'B') {
        $bookInput = jeab_ordrar_proposal_book_input($store, $row, $input);
    }
    $row['updated_at'] = jeab_now();
    $row = jeab_purchase_put($row);

    if (!$advance) {
        // Spara: bara datum och status. Ingen bokning.
        return $row;
    }

    if ($status === 'B') {
        // Utkast: keep draft, do not place order / force booking.
        $input['utkast'] = '1';
        $row = jeab_ordrar_save_bestall($input + [
            'onskad_leverans' => $delivery,
            'leverans' => $delivery,
            'bestallning_status' => $status,
        ]);
        return $row;
    }

    if ($status === 'C') {
        // Manuell/ingen: boka bara den bekräftade luckan. Ingen shoporder.
        if (!jeab_ordrar_is_booked($row, $store) && ($bookInput['book_confirmed'] ?? '') === '1') {
            $row = jeab_ordrar_create_booking($store, $bookInput + [
                'id' => (int) ($row['reminder_id'] ?? $input['id'] ?? 0),
            ]);
        }
        if (!jeab_ordrar_bestalld($row)) {
            $row = jeab_ordrar_stamp_bestalld_at($row);
            $row['updated_at'] = jeab_now();
            $row = jeab_purchase_put($row);
        }
        if (function_exists('jeab_ordrar_solkoll_after_placed')) {
            $row = jeab_ordrar_solkoll_after_placed($row);
        }
        return $row;
    }

    // A Automatisk: place order. Bokning sker i save_bestall när förslaget matchar.
    $qtyError = jeab_ordrar_bestall_qty_error($row);
    if ($qtyError !== '') {
        throw new InvalidArgumentException($qtyError);
    }
    $placeInput = $input;
    unset($placeInput['utkast']);
    $placeInput['intent'] = 'bestall';
    $placeInput['leverans'] = $delivery;
    $placeInput['onskad_leverans'] = $delivery;
    $row = jeab_ordrar_save_bestall($placeInput);
    return $row;
}

function jeab_ordrar_is_booked(array $row, ?JeabStore $store = null): bool
{
    if ((int) ($row['booking_id'] ?? 0) <= 0) {
        return false;
    }
    return jeab_ordrar_linked_booking($row, $store) !== null;
}

function jeab_ordrar_book_who(string $kind, ?string $company = null): string
{
    if (jeab_normalize_company($company) === 'malmo') {
        $crew = jeab_malmo_job_crew($kind);
        return $crew[0] ?? '';
    }
    return match ($kind) {
        'laddbox' => 'Elin',
        'batteri' => 'Roberto',
        // Sol installeras av Niman. Sam L är säljare och offertägare.
        'sol' => 'Niman',
        default => '',
    };
}

/**
 * Namn på påminnelsen, inklusive person-id (SamW) och alias (sam w).
 *
 * @return list<string>
 */
function jeab_ordrar_reminder_assignee_names(array $reminder): array
{
    $company = function_exists('jeab_reminder_company') ? jeab_reminder_company($reminder) : 'jarfalla';
    $names = jeab_assignees_from_row($reminder);
    if ($names === []) {
        foreach (['who', 'ansvarig'] as $key) {
            if (!array_key_exists($key, $reminder) || $reminder[$key] === null || $reminder[$key] === '') {
                continue;
            }
            $names = jeab_parse_assignees($reminder[$key], $company);
            if ($names !== []) {
                break;
            }
        }
    }
    $person = trim((string) ($reminder['person'] ?? ''));
    if ($person === '') {
        return $names;
    }
    $resolved = jeab_resolve_person_name($person, $company);
    if ($resolved === null && function_exists('jeab_people')) {
        foreach (jeab_people() as $one) {
            if (strcasecmp((string) ($one['id'] ?? ''), $person) === 0) {
                $resolved = (string) ($one['name'] ?? '');
                break;
            }
        }
    }
    if (is_string($resolved) && $resolved !== '' && !in_array($resolved, $names, true)) {
        $names[] = $resolved;
    }
    return $names;
}

/**
 * Ägare som ligger på ordern själv. Bokningens montör räknas inte.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_ordrar_stored_owner_names(array $row): array
{
    $company = jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
    $names = [];
    foreach (['assignees', 'assignee', 'who', 'ansvarig'] as $key) {
        if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
            continue;
        }
        foreach (jeab_parse_assignees($row[$key], $company) as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
    }
    return $names;
}

/**
 * Personal på ordern: sparade ägarfält plus person om det är en känd person.
 * Bokningens montör och produktens crew räknas inte. Utan namn syns ordern bara under Alla.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_ordrar_staff_names(array $row): array
{
    $names = jeab_ordrar_stored_owner_names($row);
    $company = jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
    $person = trim((string) ($row['person'] ?? ''));
    if ($person === '') {
        return $names;
    }
    $resolved = jeab_resolve_person_name($person, $company);
    if ($resolved === null && function_exists('jeab_person_by_token')) {
        $by = jeab_person_by_token($person);
        if (is_array($by)) {
            $candidate = trim((string) ($by['name'] ?? ''));
            $resolved = $candidate !== '' ? jeab_resolve_person_name($candidate, $company) : null;
            if ($resolved === null && ($by['company'] ?? '') === $company && $candidate !== '') {
                $resolved = $candidate;
            }
        }
    }
    if (is_string($resolved) && $resolved !== '' && !in_array($resolved, $names, true)) {
        $names[] = $resolved;
    }
    return $names;
}

/**
 * Ordrar-listan: who träffar bara explicit personal, job träffar produkten.
 * jeab_filter_orders (kalender och crew) lämnas orörd.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_filter_list(array $rows, ?string $who, ?string $job): array
{
    if ($who === null && $job === null) {
        return array_values(array_filter($rows, 'is_array'));
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($job !== null && jeab_order_job($row) !== $job) {
            continue;
        }
        if ($who !== null) {
            $company = jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
            $want = jeab_resolve_person_name($who, $company) ?? $who;
            $hit = false;
            foreach (jeab_ordrar_staff_names($row) as $name) {
                if (strcasecmp($name, $want) === 0) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Byter vem ordern tillhör (personal, inte fastighetsägare). Tomt token tar bort tillhör.
 *
 * @return array<string, mixed>
 */
function jeab_ordrar_set_staff_owner(int $id, string $token): array
{
    jeab_ordrar_require($id);
    $token = trim($token);
    if ($token === '__multi__') {
        throw new InvalidArgumentException('Välj en person i personalen, eller ingen.');
    }
    $clear = $token === '' || in_array(strtolower($token), ['none', 'ingen', 'unassigned'], true);
    $name = '';
    $personId = '';
    if (!$clear) {
        $person = function_exists('jeab_person_by_token') ? jeab_person_by_token($token) : null;
        if (!is_array($person)) {
            throw new InvalidArgumentException('Välj en person i personalen, eller ingen.');
        }
        $name = trim((string) ($person['name'] ?? ''));
        $personId = trim((string) ($person['id'] ?? ''));
        if ($name === '' || $personId === '') {
            throw new InvalidArgumentException('Välj en person i personalen, eller ingen.');
        }
    }
    $updated = jeab_purchase_patch($id, static function (array $current) use ($name, $personId): array {
        $current['assignee'] = $name;
        $current['assignees'] = $name === '' ? [] : [$name];
        $current['who'] = $name;
        $current['ansvarig'] = $name;
        $current['person'] = $personId;
        $current['updated_at'] = jeab_now();
        return $current;
    });
    if (!is_array($updated)) {
        throw new InvalidArgumentException('Ordern finns inte.');
    }
    return $updated;
}

/** Tillhör-väljaren på ett öppet ärende. Flera namn går inte att spara förrän ett valts. */
function jeab_ordrar_staff_owner_html(array $row, string $company, int $id): string
{
    $company = jeab_normalize_company($company) ?? 'jarfalla';
    $names = jeab_ordrar_staff_names($row);
    $multi = count($names) > 1;
    $current = $names[0] ?? '';
    $people = [];
    foreach (jeab_people() as $person) {
        if (!is_array($person)) {
            continue;
        }
        $pid = trim((string) ($person['id'] ?? ''));
        $pname = trim((string) ($person['name'] ?? ''));
        if ($pid === '' || $pname === '') {
            continue;
        }
        $isCurrent = false;
        foreach ($names as $name) {
            if (strcasecmp($name, $pname) === 0 || strcasecmp($name, $pid) === 0) {
                $isCurrent = true;
                break;
            }
        }
        if (($person['company'] ?? '') !== $company && !$isCurrent) {
            continue;
        }
        $people[] = $person;
    }
    $context = function_exists('jeab_ordrar_context_params') ? jeab_ordrar_context_params($company) : [];
    $action = jeab_ordrar_url(['co' => $company, 'id' => $id] + $context);
    $html = '<form class="ordrar-staff-owner v3-owner" method="post" action="' . e($action) . '">'
        . (function_exists('jeab_csrf_field') ? jeab_csrf_field() : '')
        . '<input type="hidden" name="action" value="ordrar.staff_owner">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<label class="ordrar-staff-owner-label">Tillhör <select name="staff" aria-label="Tillhör" onchange="this.form.submit()">'
        . '<option value=""' . ($names === [] ? ' selected' : '') . '>Ingen</option>';
    if ($multi) {
        $html .= '<option value="__multi__" selected disabled>Flera</option>';
    }
    foreach ($people as $person) {
        $pid = (string) $person['id'];
        $pname = (string) $person['name'];
        $selected = !$multi && $current !== '' && (strcasecmp($current, $pname) === 0 || strcasecmp($current, $pid) === 0);
        $html .= '<option value="' . e($pid) . '"' . ($selected ? ' selected' : '') . '>' . e($pname) . '</option>';
    }
    return $html . '</select></label></form>';
}

/**
 * Mina, Alla och produktpiller. Produktklick behåller who. Kalenderns piller rörs inte.
 */
function jeab_ordrar_mina_chips_html(string $company, ?string $who, ?string $job): string
{
    $company = jeab_normalize_company($company) ?? 'jarfalla';
    $meName = function_exists('jeab_who_me_name') ? jeab_who_me_name() : '';
    $meName = $meName !== '' ? $meName : null;
    $href = static function (array $params) use ($company): string {
        return jeab_ordrar_url(['co' => $company] + $params);
    };
    $minaOn = $meName !== null && $who !== null && strcasecmp($who, $meName) === 0;
    $html = '<a class="chip' . ($minaOn ? ' is-on' : '') . '" data-scope="mina" href="'
        . e($href(['who' => $meName ?? 'all', 'job' => $job ?? 'all'])) . '">Mina</a>';
    $html .= '<a class="chip' . ($who === null ? ' is-on' : '') . '" data-scope="alla" href="'
        . e($href(['who' => 'all', 'job' => $job ?? 'all'])) . '">Alla</a>';
    foreach (jeab_filter_products() as $key => $label) {
        $on = $job === $key;
        $html .= '<a class="chip chip-job is-' . e((string) $key) . ($on ? ' is-on' : '') . '" data-job="'
            . e((string) $key) . '" href="' . e($href(['who' => $who ?? 'all', 'job' => $on ? 'all' : (string) $key])) . '">'
            . e($label) . '</a>';
    }
    return $html;
}

/** @param list<string> $have @param list<string> $want */
function jeab_ordrar_owner_names_same(array $have, array $want): bool
{
    $fold = static function (array $names): array {
        $out = [];
        foreach ($names as $name) {
            $key = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $name), 'UTF-8') : strtolower(trim((string) $name));
            if ($key !== '') {
                $out[$key] = true;
            }
        }
        ksort($out);
        return array_keys($out);
    };
    return $fold($have) === $fold($want);
}

/**
 * Sätter orderns ägare till påminnelsens ansvarige, samma visningsnamn som personfiltret.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $reminder
 * @return array<string, mixed>
 */
function jeab_ordrar_apply_reminder_owner(array $row, array $reminder): array
{
    $names = jeab_ordrar_reminder_assignee_names($reminder);
    if ($names === []) {
        return $row;
    }
    $row['assignees'] = $names;
    $row['assignee'] = implode(', ', $names);
    return $row;
}

function jeab_ordrar_text_has_word(string $text, array $words): bool
{
    $fold = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    foreach ($words as $word) {
        $word = trim((string) $word);
        if ($word === '') {
            continue;
        }
        $q = preg_quote($word, '/');
        if (preg_match('/(?<![\p{L}\p{N}])' . $q . '(?![\p{L}\p{N}])/u', $fold) === 1) {
            return true;
        }
    }
    return false;
}

/** Ett entydigt produktord i fri text. Flera träffar lämnas tomma. */
function jeab_ordrar_kind_from_text(string $text): string
{
    $ladd = jeab_ordrar_text_has_word($text, ['elbilsladdare', 'laddbox']);
    $bat = jeab_ordrar_text_has_word($text, ['batterilagring', 'batteri']);
    $sol = jeab_ordrar_text_has_word($text, ['solceller', 'solpaneler', 'solcell']);
    $n = ($ladd ? 1 : 0) + ($bat ? 1 : 0) + ($sol ? 1 : 0);
    if ($n !== 1) {
        return '';
    }
    if ($ladd) {
        return 'laddbox';
    }
    if ($bat) {
        return 'batteri';
    }
    return 'sol';
}

function jeab_ordrar_reminder_product(array $reminder): string
{
    foreach (['kind', 'job_type', 'job', 'produkt', 'product', 'vad'] as $key) {
        if (!array_key_exists($key, $reminder)) {
            continue;
        }
        $kind = jeab_ordrar_ui_kind($reminder[$key]);
        if ($kind !== '') {
            return $kind;
        }
    }
    $chunks = [];
    foreach (['title', 'note', 'notes'] as $key) {
        $chunks[] = trim((string) ($reminder[$key] ?? ''));
    }
    return jeab_ordrar_kind_from_text(implode("\n", $chunks));
}

function jeab_ordrar_meab_laddbox_assignee_ok(array $reminder): bool
{
    $want = ['Kim', 'Abol', 'Sam W'];
    foreach (jeab_ordrar_reminder_assignee_names($reminder) as $name) {
        if (in_array($name, $want, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Vem Malmö bokar när en vunnen påminnelse har en känd produkt.
 * Laddbox bara om ansvarig är Kim, Abol eller Sam W. Batteri alltid Kim.
 * Solceller alltid Isaq och Abol.
 *
 * @return list<string>
 */
function jeab_ordrar_meab_book_crew(string $kind, array $reminder): array
{
    $kind = jeab_ordrar_ui_kind($kind);
    if ($kind === 'laddbox') {
        return jeab_ordrar_meab_laddbox_assignee_ok($reminder) ? jeab_malmo_job_crew('laddbox') : [];
    }
    if ($kind === 'batteri' || $kind === 'sol') {
        return jeab_malmo_job_crew($kind);
    }
    return [];
}

/** @return list<string> */
function jeab_ordrar_meab_sol_crew(): array
{
    return jeab_malmo_job_crew('sol');
}

/**
 * Malmö-sol är två heldagar: måndag–tisdag eller torsdag–fredag.
 * Onsdag är reserv och blir aldrig en bokningsdag. null om dagen inte startar ett par.
 *
 * @return array{starts_at:string,ends_at:string}|null
 */
function jeab_ordrar_meab_sol_pair_from_day(DateTimeImmutable $day): ?array
{
    $day = $day->setTime(0, 0, 0);
    $n = (int) $day->format('N');
    if ($n !== 1 && $n !== 4) {
        return null;
    }
    return [
        'starts_at' => $day->format('Y-m-d') . ' 00:00:00',
        'ends_at' => $day->modify('+2 days')->format('Y-m-d') . ' 00:00:00',
    ];
}

function jeab_ordrar_meab_sol_pair_has_wednesday(array $pair): bool
{
    try {
        $start = (new DateTimeImmutable((string) ($pair['starts_at'] ?? '')))->setTime(0, 0);
        $end = (new DateTimeImmutable((string) ($pair['ends_at'] ?? '')))->setTime(0, 0);
    } catch (Exception) {
        return false;
    }
    for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
        if ((int) $day->format('N') === 3) {
            return true;
        }
    }
    return false;
}

/**
 * @param list<array<string,mixed>> $busy
 * @param array<string, list<array<string,mixed>>> $outlookByWho
 * @param list<string> $crew
 */
function jeab_ordrar_crew_span_busy(array $busy, array $outlookByWho, array $crew, string $start, string $end): bool
{
    foreach ($crew as $who) {
        $who = (string) $who;
        if (jeab_ordrar_slot_busy($busy, $who, $start, $end)) {
            return true;
        }
        $events = $outlookByWho[$who] ?? [];
        if (is_array($events) && jeab_ordrar_outlook_slot_busy($events, $start, $end)) {
            return true;
        }
    }
    return false;
}

/**
 * Första lediga måndag–tisdag eller torsdag–fredag från och med $startDay.
 * Båda i gänget måste vara lediga hela paret. Onsdag hoppas över.
 *
 * @param list<array<string,mixed>> $busy
 * @param array<string, list<array<string,mixed>>> $outlookByWho
 * @param list<string> $crew
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_pick_meab_sol_pair(array $busy, array $outlookByWho, array $crew, DateTimeImmutable $startDay): array
{
    $startDay = $startDay->setTime(0, 0, 0);
    for ($i = 0; $i < 180; $i++) {
        $day = $startDay->modify('+' . $i . ' days');
        $pair = jeab_ordrar_meab_sol_pair_from_day($day);
        if ($pair === null) {
            continue;
        }
        if (jeab_ordrar_crew_span_busy($busy, $outlookByWho, $crew, $pair['starts_at'], $pair['ends_at'])) {
            continue;
        }
        return $pair;
    }
    throw new InvalidArgumentException('Ingen ledig måndag–tisdag eller torsdag–fredag hittades.');
}

/**
 * @param list<string> $crew
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_search_meab_sol(JeabStore $store, DateTimeImmutable $startDay, ?string $company, array $crew): array
{
    $lookFrom = $startDay->modify('-14 days');
    $lookTo = $startDay->modify('+180 days');
    $busy = $store->bookingsBetween($lookFrom->format('Y-m-d H:i:s'), $lookTo->format('Y-m-d H:i:s'));
    $outlook = [];
    $live = jeab_ordrar_external_live() && function_exists('jeab_gp_busy_for_person');
    foreach ($crew as $who) {
        $outlook[(string) $who] = $live ? jeab_gp_busy_for_person((string) $who, $lookFrom, $lookTo, $company) : [];
    }
    return jeab_ordrar_pick_meab_sol_pair($busy, $outlook, $crew, $startDay);
}

/**
 * @param list<string>|null $crew
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_first_free_meab_sol(JeabStore $store, int $minDays, ?string $company = null, ?array $crew = null): array
{
    $tz = new DateTimeZone('Europe/Stockholm');
    $startDay = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0)->modify('+' . max(0, $minDays) . ' days');
    return jeab_ordrar_search_meab_sol($store, $startDay, $company, $crew ?? jeab_ordrar_meab_sol_crew());
}

/**
 * @param list<string>|null $crew
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_first_free_meab_sol_on_or_after(JeabStore $store, string $fromYmd, ?string $company = null, ?array $crew = null): array
{
    $startDay = jeab_ordrar_day($fromYmd);
    if (!$startDay instanceof DateTimeImmutable) {
        throw new InvalidArgumentException('Ogiltigt datum.');
    }
    $tz = new DateTimeZone('Europe/Stockholm');
    $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
    if ($startDay < $today) {
        $startDay = $today;
    }
    return jeab_ordrar_search_meab_sol($store, $startDay, $company, $crew ?? jeab_ordrar_meab_sol_crew());
}

/**
 * #vunnen i Malmö sätter produkt och bolag. Ingen tid skrivs här.
 * Föreslagen tid visas i Bekräfta och bokas först när säljaren bekräftar:
 * laddbox-halvdag och batteri-heldag på Kim, solceller måndag–tisdag eller
 * torsdag–fredag på Isaq och Abol. Järfälla rörs inte.
 */
function jeab_ordrar_autobook_meab_won(JeabStore $store, array $reminder, array $purchase): array
{
    if (jeab_ordrar_is_booked($purchase, $store)) {
        return $purchase;
    }
    $company = jeab_normalize_company($purchase['company'] ?? null) ?? jeab_reminder_company($reminder);
    if ($company !== 'malmo') {
        return $purchase;
    }
    $kind = jeab_ordrar_ui_kind($purchase['kind'] ?? '');
    if ($kind === '') {
        $kind = jeab_ordrar_vunnen_products($reminder)[0] ?? '';
    }
    if ($kind === '' || jeab_ordrar_vunnen_product_ambiguous($reminder) || jeab_ordrar_meab_book_crew($kind, $reminder) === []) {
        return $purchase;
    }
    if (jeab_ordrar_ui_kind($purchase['kind'] ?? '') === '') {
        $purchase['kind'] = $kind;
        $purchase['company'] = 'malmo';
        $purchase['updated_at'] = jeab_now();
        $purchase = jeab_purchase_put($purchase);
    }
    return $purchase;
}

/**
 * Minsta antal kalenderdagar fram till installation för laddbox och sol.
 * Batteri räknas i arbetsdagar, se jeab_ordrar_book_earliest_day().
 */
function jeab_ordrar_book_min_days(string $kind): int
{
    return $kind === 'laddbox' ? 2 : 14;
}

/**
 * Batteri: leverans tar minst 3 arbetsdagar och installation sker tidigast
 * 1 arbetsdag efter leverans. Beställningsdagen räknas inte.
 */
function jeab_ordrar_battery_lead_workdays(): int
{
    return 4;
}

function jeab_ordrar_book_today(?DateTimeImmutable $today = null): DateTimeImmutable
{
    $tz = new DateTimeZone('Europe/Stockholm');
    $day = $today instanceof DateTimeImmutable ? $today->setTimezone($tz) : new DateTimeImmutable('now', $tz);
    return $day->setTime(0, 0, 0);
}

/**
 * Påskdagen (gregoriansk, utan ext-calendar).
 */
function jeab_ordrar_easter_sunday(int $year): DateTimeImmutable
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), new DateTimeZone('Europe/Stockholm'));
}

/**
 * Svenska röda dagar plus midsommarafton, julafton och nyårsafton (arbetsfria).
 *
 * @return array<string,string> Y-m-d => namn
 */
function jeab_ordrar_se_holidays(int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }
    $y = sprintf('%04d', $year);
    $days = [
        $y . '-01-01' => 'Nyårsdagen',
        $y . '-01-06' => 'Trettondedag jul',
        $y . '-05-01' => 'Första maj',
        $y . '-06-06' => 'Nationaldagen',
        $y . '-12-24' => 'Julafton',
        $y . '-12-25' => 'Juldagen',
        $y . '-12-26' => 'Annandag jul',
        $y . '-12-31' => 'Nyårsafton',
    ];
    $easter = jeab_ordrar_easter_sunday($year);
    $days[$easter->modify('-2 days')->format('Y-m-d')] = 'Långfredagen';
    $days[$easter->modify('-1 day')->format('Y-m-d')] = 'Påskafton';
    $days[$easter->format('Y-m-d')] = 'Påskdagen';
    $days[$easter->modify('+1 day')->format('Y-m-d')] = 'Annandag påsk';
    $days[$easter->modify('+39 days')->format('Y-m-d')] = 'Kristi himmelsfärdsdag';
    $days[$easter->modify('+49 days')->format('Y-m-d')] = 'Pingstdagen';
    $tz = new DateTimeZone('Europe/Stockholm');
    for ($d = 19; $d <= 25; $d++) {
        $day = new DateTimeImmutable(sprintf('%s-06-%02d 00:00:00', $y, $d), $tz);
        if ((int) $day->format('N') === 5) {
            $days[$day->format('Y-m-d')] = 'Midsommarafton';
            $days[$day->modify('+1 day')->format('Y-m-d')] = 'Midsommardagen';
            break;
        }
    }
    $nov = new DateTimeImmutable($y . '-10-31 00:00:00', $tz);
    for ($i = 0; $i < 7; $i++) {
        $day = $nov->modify('+' . $i . ' days');
        if ((int) $day->format('N') === 6) {
            $days[$day->format('Y-m-d')] = 'Alla helgons dag';
            break;
        }
    }
    $cache[$year] = $days;
    return $days;
}

function jeab_ordrar_is_workday(DateTimeImmutable $day): bool
{
    if ((int) $day->format('N') >= 6) {
        return false;
    }
    return !isset(jeab_ordrar_se_holidays((int) $day->format('Y'))[$day->format('Y-m-d')]);
}

function jeab_ordrar_add_workdays(DateTimeImmutable $from, int $workdays): DateTimeImmutable
{
    $day = $from->setTime(0, 0, 0);
    $left = max(0, $workdays);
    for ($guard = 0; $left > 0 && $guard < 400; $guard++) {
        $day = $day->modify('+1 day');
        if (jeab_ordrar_is_workday($day)) {
            $left--;
        }
    }
    return $day;
}

/**
 * Gemensam regel för tidigaste installationsdag, används av både Boka och Boka+Beställ.
 * Batteri: fjärde arbetsdagen efter beställningsdagen (helger och röda dagar hoppas över).
 * Laddbox: 2 dagar. Sol: 14 dagar.
 */
function jeab_ordrar_book_earliest_day(string $kind, ?DateTimeImmutable $today = null): DateTimeImmutable
{
    $kind = jeab_purchase_normalize_kind($kind) ?? $kind;
    $base = jeab_ordrar_book_today($today);
    if ($kind === 'batteri') {
        return jeab_ordrar_add_workdays($base, jeab_ordrar_battery_lead_workdays());
    }
    return $base->modify('+' . jeab_ordrar_book_min_days($kind) . ' days');
}

/**
 * Hjälptext om föreslagen installation, per produkt.
 */
function jeab_ordrar_book_lead_help(string $kind): string
{
    $kind = jeab_purchase_normalize_kind($kind) ?? $kind;
    return match ($kind) {
        'batteri' => 'Föreslagen installation är första lediga tid från fjärde arbetsdagen efter beställning.',
        'laddbox' => 'Föreslagen installation är första lediga tid minst två dagar framåt.',
        default => 'Föreslagen installation är första lediga tid minst två veckor framåt.',
    };
}

/**
 * Första lediga tid enligt den gemensamma regeln. Vem som bokas avgörs av anroparen.
 *
 * @param list<string>|null $crew
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_first_free_slot(JeabStore $store, string $kind, string $who, ?string $company = null, ?array $crew = null, ?DateTimeImmutable $today = null, ?bool $meabSol = null): array
{
    $kind = jeab_purchase_normalize_kind($kind) ?? $kind;
    $startDay = jeab_ordrar_book_earliest_day($kind, $today);
    $meabSol ??= jeab_normalize_company($company) === 'malmo';
    if ($kind === 'sol' && $meabSol) {
        return jeab_ordrar_search_meab_sol($store, $startDay, $company, ($crew !== null && $crew !== []) ? $crew : jeab_ordrar_meab_sol_crew());
    }
    if ($kind === 'sol') {
        return jeab_ordrar_sol_span_search($store, $who, $startDay, $company);
    }
    return jeab_ordrar_search_day_window($store, $who, $startDay, $kind, $company);
}

function jeab_ordrar_booking_has_who(array $booking, string $who): bool
{
    $company = function_exists('jeab_booking_company') ? jeab_booking_company($booking) : 'jarfalla';
    $want = jeab_parse_assignees($who, $company);
    if ($want === []) {
        $want = jeab_parse_assignees($who);
    }
    $have = jeab_assignees_from_row($booking);
    foreach ($want as $name) {
        foreach ($have as $got) {
            if (strcasecmp((string) $got, (string) $name) === 0) {
                return true;
            }
        }
    }
    return false;
}

function jeab_ordrar_times_overlap(string $start, string $end, string $otherStart, string $otherEnd): bool
{
    return $otherStart !== '' && $otherStart < $end && ($otherEnd !== '' ? $otherEnd : $otherStart) > $start;
}

function jeab_ordrar_slot_busy(array $bookings, string $who, string $start, string $end): bool
{
    foreach ($bookings as $booking) {
        if (!is_array($booking) || !jeab_ordrar_booking_has_who($booking, $who)) {
            continue;
        }
        if (function_exists('jeab_outlook_resource_blocks') && !jeab_outlook_resource_blocks((int) ($booking['id'] ?? 0), $who)) {
            continue;
        }
        $s = (string) ($booking['starts_at'] ?? '');
        $e = (string) ($booking['ends_at'] ?? $s);
        if (jeab_ordrar_times_overlap($start, $end, $s, $e)) {
            return true;
        }
    }
    return false;
}

function jeab_ordrar_outlook_slot_busy(array $events, string $start, string $end): bool
{
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $s = (string) ($event['starts_at'] ?? '');
        $e = (string) ($event['ends_at'] ?? $s);
        if (jeab_ordrar_times_overlap($start, $end, $s, $e)) {
            return true;
        }
    }
    return false;
}

function jeab_ordrar_first_free_halfday(JeabStore $store, string $who, int $minDays, ?string $company = null, string $kind = 'laddbox'): array
{
    $tz = new DateTimeZone('Europe/Stockholm');
    $startDay = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0)->modify('+' . max(0, $minDays) . ' days');
    return jeab_ordrar_search_day_window($store, $who, $startDay, $kind, $company);
}

function jeab_ordrar_booking_customer_name(JeabStore $store, array $row): string
{
    $huvud = trim((string) ($row['huvudsokande'] ?? ''));
    if ($huvud !== '') {
        return $huvud;
    }
    $reminder = jeab_get_reminder($store, (int) ($row['reminder_id'] ?? 0));
    $customer = jeab_ordrar_customer_name(is_array($reminder) ? $reminder : null);
    $title = is_array($reminder) ? trim((string) ($reminder['title'] ?? '')) : '';
    if ($customer !== '' && !jeab_ordrar_is_title_or_product_word($customer, $title)) {
        return $customer;
    }
    $address = jeab_ordrar_lookup_query(is_array($reminder) ? $reminder : null, $row);
    $lookup = jeab_ordrar_lookup_reject_prefix(
        jeab_ordrar_address_lookup($address),
        jeab_ordrar_lookup_customer_address($address, $row)
    );
    $picked = jeab_ordrar_match_owner($lookup['owners'], $customer);
    $name = trim((string) ($picked['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    foreach ($lookup['owners'] as $owner) {
        $name = trim((string) ($owner['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }
    return $customer !== '' ? $customer : 'Kund';
}

function jeab_ordrar_maybe_book_current(JeabStore $store, array $row, ?string $error = null): array
{
    if (jeab_ordrar_is_booked($row)) {
        return $row;
    }
    // Batteri och sol bokas bara när Beställ bekräftar förslaget.
    if (jeab_ordrar_books_at_bestall($row)) {
        return $row;
    }
    if (!jeab_ordrar_is_solbatteri($row) && trim((string) ($row['fortnox_order'] ?? '')) !== '') {
        return $row;
    }
    unset($error);
    $view = jeab_ordrar_view_stop($row, null);
    if ($view !== 'boka' && $view !== 'boka_elin') {
        return $row;
    }
    return jeab_ordrar_create_booking($store, ['id' => (int) ($row['reminder_id'] ?? 0)]);
}

function jeab_ordrar_install_what(array $row): string
{
    $what = trim((string) ($row['install_what'] ?? ''));
    if ($what !== '') {
        return $what;
    }
    $kwh = trim((string) ($row['kwh'] ?? ''));
    $vaxel = trim((string) ($row['vaxelriktare'] ?? ''));
    return trim(implode(' ', array_values(array_filter([$kwh, $vaxel], static fn(string $part): bool => $part !== ''))));
}

function jeab_ordrar_install_note(array $row): string
{
    return trim((string) ($row['install_note'] ?? ''));
}

/** Bara anteckningen. Bekräfta-stämpeln och personnummer följer inte med. */
function jeab_ordrar_save_install_note(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    if (!array_key_exists('install_note', $input)) {
        throw new InvalidArgumentException('Anteckningen saknas.');
    }
    $note = trim((string) $input['install_note']);
    if (mb_strlen($note) > 4000) {
        throw new InvalidArgumentException('Anteckningen är för lång.');
    }
    $rev = (int) ($input['note_rev'] ?? 0);
    if ($rev < 0) {
        $rev = 0;
    }
    $prevRev = (int) ($row['install_note_rev'] ?? 0);
    // pagehide kan hinna före. En sen fetch med lägre note_rev får inte skriva tillbaka den äldre texten.
    if ($rev > 0 && $prevRev > $rev) {
        return $row;
    }
    $row['install_note'] = $note;
    if ($rev > 0) {
        $row['install_note_rev'] = $rev;
    }
    $row['updated_at'] = jeab_now();
    $saved = jeab_purchase_put($row);
    $id = (int) ($saved['reminder_id'] ?? $row['reminder_id'] ?? 0);
    if (function_exists('jeab_purchase_cache_forget')) {
        jeab_purchase_cache_forget();
    }
    $stored = $id > 0 ? jeab_purchase_get($id) : null;
    if (!is_array($stored) || (string) ($stored['install_note'] ?? '') !== $note) {
        throw new RuntimeException('Anteckningen kunde inte sparas.');
    }
    jeab_ordrar_refresh_linked_booking_notes($stored);

    return $stored;
}

/** Sidvisning får fylla andra fält, men inte skriva tillbaka en äldre anteckning. */
function jeab_ordrar_put_leaving_note(array $row): array
{
    unset($row['install_note'], $row['install_note_rev']);

    return jeab_purchase_put($row);
}

function jeab_ordrar_refresh_linked_booking_notes(array $row): void
{
    $bookingId = (int) ($row['booking_id'] ?? 0);
    if ($bookingId <= 0 || !function_exists('jeab_update_booking')) {
        return;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return;
    }
    $booking = jeab_get_booking($store, $bookingId);
    if (!$booking || ($booking['status'] ?? '') === 'cancelled') {
        return;
    }
    $reminder = jeab_get_reminder($store, (int) ($row['reminder_id'] ?? 0));
    $notes = jeab_booking_site_notes_from_sources($booking, $row, is_array($reminder) ? $reminder : null);
    if ((string) ($booking['notes'] ?? '') === $notes) {
        return;
    }
    try {
        jeab_update_booking($store, $bookingId, ['notes' => $notes]);
    } catch (Throwable) {
        // Anteckning till kalendern får inte stoppa ordersparandet.
    }
}

function jeab_ordrar_create_booking(JeabStore $store, array $input): array
{
    $reminderId = (int) ($input['id'] ?? 0);
    $run = static function () use ($store, $input): array {
        return jeab_ordrar_create_booking_locked($store, $input);
    };
    if ($reminderId > 0 && function_exists('jeab_reminder_lock')) {
        return jeab_reminder_lock($reminderId, $run);
    }
    return $run();
}

/**
 * Vem och vilken tid Bekräfta skulle boka. Läser kalendern, skriver inget.
 *
 * @return array{starts_at:string,ends_at:string,who:list<string>,kind:string}|null
 */
function jeab_ordrar_proposed_slot(JeabStore $store, array $row, array $input = []): ?array
{
    if (jeab_ordrar_is_booked($row, $store)) {
        return null;
    }
    $kind = jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '';
    if (!in_array($kind, ['laddbox', 'batteri', 'sol'], true)) {
        return null;
    }
    if ($kind !== 'sol' && $kind !== 'batteri' && trim((string) ($row['fortnox_order'] ?? '')) !== '') {
        return null;
    }
    $reminder = jeab_get_reminder($store, (int) ($row['reminder_id'] ?? 0));
    $companyNorm = jeab_normalize_company($row['company'] ?? null);
    if ($companyNorm === null && is_array($reminder)) {
        $companyNorm = jeab_reminder_company($reminder);
    }
    // Malmö visar Malmös lag. Övriga bolag visar samma person som bokningen använder.
    // Läser kalendern. Skriver ingen bokning.
    $crew = $companyNorm === 'malmo' ? jeab_malmo_job_crew($kind) : [];
    $who = $crew !== [] ? (string) $crew[0] : jeab_ordrar_book_who($kind, $companyNorm);
    if ($who === '') {
        return null;
    }
    try {
        $slot = jeab_ordrar_pick_book_slot($store, $row, $input, $kind, $who, $crew);
    } catch (Throwable) {
        return null;
    }
    return [
        'starts_at' => (string) $slot['starts_at'],
        'ends_at' => (string) $slot['ends_at'],
        'who' => $crew !== [] ? $crew : [$who],
        'kind' => $kind,
    ];
}

/**
 * «Fortnox-order saknas.» sparades innan leveransdatum svarade vantar_order.
 * Utan Fortnox-order och utan fakturanummer är det inte ett fel.
 *
 * @param array<string, mixed> $row
 */
function jeab_ordrar_stale_leverans_missing_order(array $row): bool
{
    if (trim((string) ($row['fortnox_leverans_error'] ?? '')) !== 'Fortnox-order saknas.') {
        return false;
    }
    $orderNo = trim((string) ($row['fortnox_order'] ?? ''));
    $invoiceNo = trim((string) ($row['fortnox_invoice'] ?? ''));
    if ($invoiceNo === '0') {
        $invoiceNo = '';
    }

    return $orderNo === '' && $invoiceNo === '';
}

/**
 * Tömmer fortnox_leverans_error där texten är «Fortnox-order saknas.» och ordern
 * saknar Fortnox-order och faktura. dry=1 är standard och skriver inget.
 * Bara det fältet. Inget Fortnox-anrop.
 *
 * POST /hook.php
 * Authorization: Bearer <inkorg-token>
 * {"action":"ordrar.clear_stale_leverans_error"}
 * {"action":"ordrar.clear_stale_leverans_error","dry":0}
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_clear_stale_leverans_error(array $input = []): array
{
    $dry = jeab_ordrar_backfill_wants_dry($input);
    $ids = [];
    foreach (jeab_purchase_all() as $key => $row) {
        if (!is_array($row) || !jeab_ordrar_stale_leverans_missing_order($row)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? $key);
        if ($id <= 0) {
            continue;
        }
        $ids[$id] = $id;
    }
    $ids = array_values($ids);
    sort($ids);
    if (!$dry) {
        $written = [];
        foreach ($ids as $id) {
            $current = jeab_purchase_get($id);
            if (!is_array($current) || !jeab_ordrar_stale_leverans_missing_order($current)) {
                continue;
            }
            jeab_purchase_put([
                'reminder_id' => $id,
                'fortnox_leverans_error' => '',
            ]);
            $written[] = $id;
        }
        $ids = $written;
    }

    return [
        'ok' => true,
        'action' => 'ordrar.clear_stale_leverans_error',
        'dry' => $dry ? 1 : 0,
        'ids' => $ids,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_clear_stale_leverans_error_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.clear_stale_leverans_error', 'status' => 401];
    }
    $result = jeab_ordrar_clear_stale_leverans_error($input);
    $result['status'] = 200;

    return $result;
}

/**
 * Fortnox-svaret för DeliveryDate sparas på ordern och visas. Bokning och faktura
 * står kvar. Personnummer skalas ur texten. booking_id får inte tappas.
 *
 * @param array<string,mixed>|Throwable|null $result
 */
function jeab_ordrar_remember_leveransdatum(array $row, mixed $result): array
{
    $fail = '';
    if ($result instanceof Throwable) {
        $fail = trim($result->getMessage());
    } elseif (is_array($result)) {
        $status = (string) ($result['status'] ?? '');
        $quiet = in_array($status, ['vantar_order', 'ingen_bokning', 'test'], true);
        $ok = array_key_exists('ok', $result) ? (bool) $result['ok'] : !in_array($status, ['fel', 'last_i_fortnox'], true);
        if (!$quiet && (!$ok || $status === 'fel' || $status === 'last_i_fortnox')) {
            $fail = trim((string) ($result['error'] ?? ''));
            if ($fail === '' && $status === 'last_i_fortnox') {
                $fail = trim((string) ($result['advice'] ?? ''));
            }
            if ($fail === '') {
                $fail = 'Fortnox tog inte emot leveransdatum.';
            }
        }
    }
    $bookingId = (int) ($row['booking_id'] ?? 0);
    if ($fail !== '') {
        $scrubbed = jeab_ordrar_scrub_pnr($fail);
        $error = is_string($scrubbed) && $scrubbed !== '' ? $scrubbed : 'Fortnox tog inte emot leveransdatum.';
    } else {
        // Tom sträng, inte unset: sparningen slår ihop fält och skulle annars lämna det gamla felet.
        $error = '';
    }
    $row['fortnox_leverans_error'] = $error;
    if ($bookingId > 0) {
        $row['booking_id'] = $bookingId;
    }
    // Bara felet och bokningsid. Hela raden (t.ex. en målad tom adress) får inte skrivas.
    $id = (int) ($row['reminder_id'] ?? 0);
    $patch = ['reminder_id' => $id, 'fortnox_leverans_error' => $error];
    if ($bookingId > 0) {
        $patch['booking_id'] = $bookingId;
    }
    if ($id > 0 && function_exists('jeab_purchase_put')) {
        $saved = jeab_purchase_put($patch);
        if (is_array($saved)) {
            $row['fortnox_leverans_error'] = (string) ($saved['fortnox_leverans_error'] ?? $error);
            if ($bookingId > 0) {
                $row['booking_id'] = (int) ($saved['booking_id'] ?? $bookingId);
            }
            foreach (['kind', 'natbolag', 'address', 'huvudsokande_address', 'gata', 'ort', 'huvudsokande', 'name'] as $key) {
                $kept = trim((string) ($saved[$key] ?? ''));
                if ($kept !== '') {
                    $row[$key] = $saved[$key];
                }
            }
        }
    }
    if ($bookingId > 0 && (int) ($row['booking_id'] ?? 0) !== $bookingId) {
        $row['booking_id'] = $bookingId;
        if ($id > 0 && function_exists('jeab_purchase_put')) {
            $saved = jeab_purchase_put(['reminder_id' => $id, 'booking_id' => $bookingId]);
            if (is_array($saved) && (int) ($saved['booking_id'] ?? 0) === $bookingId) {
                $row['booking_id'] = $bookingId;
            }
        }
    }
    return $row;
}

function jeab_ordrar_proposed_slot_text(array $slot): string
{
    $startRaw = (string) ($slot['starts_at'] ?? '');
    $endRaw = (string) ($slot['ends_at'] ?? '');
    $when = jeab_booking_when(['starts_at' => $startRaw, 'ends_at' => $endRaw]);
    $label = trim($when['day'] . ($when['clock'] !== '' ? ' ' . $when['clock'] : ''));
    try {
        $tz = new DateTimeZone('Europe/Stockholm');
        $start = new DateTimeImmutable($startRaw, $tz);
        $end = new DateTimeImmutable($endRaw, $tz);
        $inclusive = $end->format('H:i') === '00:00' ? $end->modify('-1 day') : $end;
        if ($inclusive->format('Y-m-d') !== $start->format('Y-m-d')) {
            $label .= '–' . jeab_sv_weekday($inclusive) . ' ' . $inclusive->format('j') . ' ' . jeab_sv_month($inclusive);
        }
    } catch (Throwable) {
        $label = $label !== '' ? $label : $startRaw;
    }
    $who = [];
    foreach ($slot['who'] ?? [] as $name) {
        $name = trim((string) $name);
        if ($name !== '' && !in_array($name, $who, true)) {
            $who[] = $name;
        }
    }
    $text = 'Föreslagen tid: ' . $label;
    if ($who !== []) {
        $text .= ' · ' . implode(', ', $who);
    }
    return $text;
}

/** Synlig slot i Bekräfta. Tom sträng när ingen tid ska föreslås. Skriver ingen bokning. */
function jeab_ordrar_proposed_slot_html(JeabStore $store, array $row, string $class = 'ordrar-help'): string
{
    $slot = jeab_ordrar_proposed_slot($store, $row);
    if ($slot === null) {
        return '';
    }
    $stamp = (string) $slot['starts_at'] . '|' . (string) $slot['ends_at'];
    return '<p class="' . e($class) . '" data-proposed-slot="' . e($stamp) . '">' . e(jeab_ordrar_proposed_slot_text($slot)) . '</p>';
}

/**
 * Samma lucka som Bekräfta bokar. Kastar om ingen tid finns.
 *
 * @param list<string> $crew
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_ordrar_pick_book_slot(JeabStore $store, array $row, array $input, string $kind, string $who, array $crew): array
{
    $prefer = jeab_ordrar_ymd((string) ($input['install_date'] ?? $input['install_ymd'] ?? $row['install_date'] ?? ''));
    $clock = jeab_ordrar_install_clock((string) ($input['install_time'] ?? $row['install_time'] ?? ''));
    $company = $row['company'] ?? null;
    if ($prefer === '') {
        return jeab_ordrar_first_free_slot($store, $kind, $who, $company, $crew !== [] ? $crew : null, null, $crew !== []);
    }
    if ($kind === 'sol' && $crew !== []) {
        return jeab_ordrar_first_free_meab_sol_on_or_after($store, $prefer, $company, $crew);
    }
    if ($kind === 'sol') {
        return jeab_ordrar_first_free_sol_days_on_or_after($store, $who, $prefer, $company);
    }
    return jeab_ordrar_first_free_halfday_on_or_after($store, $who, $prefer, $company, $kind, $clock);
}

/**
 * Kontakt som en ny bokning ska ärva. Orderns contact_id, annars påminnelsens.
 * Tom sträng när ingen av dem är kopplad. Slår inte ihop kontakter.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $reminder
 */
function jeab_ordrar_booking_inherit_contact_id(array $row, ?array $reminder): string
{
    $id = trim((string) ($row['contact_id'] ?? ''));
    if ($id !== '') {
        return $id;
    }
    if (is_array($reminder)) {
        return trim((string) ($reminder['contact_id'] ?? ''));
    }

    return '';
}

function jeab_ordrar_create_booking_locked(JeabStore $store, array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    if (jeab_ordrar_is_booked($row)) {
        return $row;
    }
    $kind = jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '';
    // Laddbox bokas i samma veva som Fortnox. Batteri och sol kan bokas efteråt.
    if ($kind !== 'sol' && $kind !== 'batteri' && trim((string) ($row['fortnox_order'] ?? '')) !== '') {
        return $row;
    }
    $reminder = jeab_get_reminder($store, (int) ($row['reminder_id'] ?? 0));
    $companyNorm = jeab_normalize_company($row['company'] ?? null);
    if ($companyNorm === null && is_array($reminder)) {
        $companyNorm = jeab_reminder_company($reminder);
    }
    // Malmö bokar alltid Malmös lag: laddbox och batteri på Kim, sol på Isaq och Abol.
    // Vem påminnelsen ligger på styr bara om en tid föreslås när affären vinns (jeab_ordrar_meab_book_crew),
    // inte vem som bokas här. Förr föll Malmö-laddbox tillbaka på Järfällas Elin.
    $crew = $companyNorm === 'malmo' ? jeab_malmo_job_crew($kind) : [];
    $who = $crew !== [] ? (string) $crew[0] : jeab_ordrar_book_who($kind, $companyNorm);
    if ($who === '') {
        throw new InvalidArgumentException('Välj Elbilsladdare, Batteri eller Solpaneler först.');
    }
    // Beställ har redan visat luckan. Boka exakt den, utan att söka om eller flytta.
    $keepSlot = (string) ($input['keep_slot'] ?? '') === '1'
        && trim((string) ($input['proposal_starts'] ?? '')) !== ''
        && trim((string) ($input['proposal_ends'] ?? '')) !== '';
    if ($keepSlot) {
        $slot = [
            'starts_at' => trim((string) $input['proposal_starts']),
            'ends_at' => trim((string) $input['proposal_ends']),
        ];
    } else {
        $slot = jeab_ordrar_pick_book_slot($store, $row, $input, $kind, $who, $crew);
    }
    $assignees = $crew !== [] ? $crew : [$who];
    $name = jeab_ordrar_booking_customer_name($store, $row);
    $notes = jeab_booking_site_notes_from_sources([], $row, is_array($reminder) ? $reminder : null);
    $inheritContact = jeab_ordrar_booking_inherit_contact_id($row, is_array($reminder) ? $reminder : null);
    $booking = jeab_create_booking(
        $store,
        $name !== '' ? $name : 'Kund',
        $assignees,
        $slot['starts_at'],
        $slot['ends_at'],
        $kind,
        [
            'company' => $row['company'] ?? 'jarfalla',
            'assignees' => $assignees,
            'address' => (string) ($row['huvudsokande_address'] ?? $row['address'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'notes' => $notes,
            'contact_id' => $inheritContact,
            'reminder_id' => (int) ($row['reminder_id'] ?? 0),
        ],
        false
    );
    if (!is_array($row['steps'] ?? null)) {
        $row['steps'] = [];
    }
    $row['steps']['bokad_tid'] = (string) ($booking['starts_at'] ?? $slot['starts_at']);
    $row['booking_id'] = (int) ($booking['id'] ?? 0);
    $bookedClock = jeab_ordrar_install_clock((string) ($input['install_time'] ?? ''));
    if ($bookedClock !== '') {
        $row['install_time'] = $bookedClock;
    }
    $delivery = jeab_ordrar_actual_delivery($row);
    // Bekräftad Beställ-lucka flyttas inte till leverans plus sju dagar.
    if (!$keepSlot && $kind === 'sol' && $delivery !== '') {
        $row = jeab_ordrar_move_booking_for_delivery($row, $delivery, $store);
    }
    if ($kind === 'sol' || $keepSlot) {
        $row = jeab_ordrar_persist_onskad($row, $store);
    }
    $row['updated_at'] = jeab_now();
    $row = jeab_purchase_put($row);
    $deliveryFile = __DIR__ . '/fortnox_leveransdatum.php';
    if (is_file($deliveryFile)) {
        require_once $deliveryFile;
    }
    if (function_exists('jeab_fortnox_leveransdatum_push')) {
        $deliveryResult = null;
        try {
            $deliveryResult = jeab_fortnox_leveransdatum_push($row, true);
        } catch (Throwable $e) {
            $deliveryResult = $e;
        }
        $row = jeab_ordrar_remember_leveransdatum($row, $deliveryResult);
    }
    return $row;
}

function jeab_ordrar_paminelse_mmdd(array $row): string
{
    $raw = trim((string) ($row['reminder_created_at'] ?? ''));
    if ($raw === '') {
        $raw = trim((string) ($row['reminder_created'] ?? $row['createdAt'] ?? ''));
    }
    return jeab_ordrar_mmdd($raw);
}

function jeab_ordrar_vad_date(array $row): string
{
    return jeab_ordrar_mmdd((string) ((is_array($row['steps'] ?? null) ? $row['steps'] : [])['vad'] ?? ''));
}

function jeab_ordrar_vad_done(array $row): bool
{
    if (jeab_ordrar_vad_date($row) !== '') {
        return true;
    }
    if (jeab_ordrar_spec_done($row) || jeab_ordrar_orderrader_saved($row) || jeab_ordrar_is_booked($row) || jeab_ordrar_bestalld($row)) {
        return true;
    }
    return trim((string) ($row['fortnox_order'] ?? '')) !== '' && jeab_ordrar_is_solbatteri($row);
}

function jeab_ordrar_fakturering_date(array $row): string
{
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    $raw = trim((string) ($steps['faktura_skapad'] ?? ''));
    if ($raw === '') {
        $raw = trim((string) ($steps['fardiganmald'] ?? ''));
    }
    if ($raw === '') {
        $raw = trim((string) ($steps['order_skriven'] ?? ''));
    }
    return jeab_ordrar_mmdd($raw);
}

function jeab_ordrar_fakturering_done(array $row): bool
{
    if (jeab_ordrar_has_invoice($row)) {
        return true;
    }
    if (jeab_ordrar_fardiganmal_date($row) !== '') {
        return true;
    }
    if (!jeab_ordrar_bekrafta_slutfor($row)) {
        return false;
    }
    if (trim((string) ($row['fortnox_order'] ?? '')) === '') {
        return false;
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    if (trim((string) ($steps['order_skriven'] ?? '')) !== '') {
        return true;
    }
    return jeab_ordrar_is_booked($row);
}

function jeab_ordrar_mark_flow_current(array $steps): array
{
    $currentSet = false;
    foreach ($steps as $i => $step) {
        $steps[$i]['current'] = false;
        if (!isset($steps[$i]['pair'])) {
            $steps[$i]['pair'] = '';
        }
        if (empty($step['done']) && !$currentSet) {
            $steps[$i]['current'] = true;
            $currentSet = true;
        }
    }
    return $steps;
}

function jeab_ordrar_flow(array $row): array
{
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    $confirmed = $kind !== '';
    $flow = [
        ['key' => 'bekrafta', 'label' => 'Bekräfta', 'done' => $confirmed, 'date' => jeab_ordrar_bekrafta_date($row)],
    ];
    if (!$confirmed) {
        return jeab_ordrar_mark_flow_current($flow);
    }
    if ($kind === 'laddbox') {
        $fxDone = jeab_ordrar_fakturering_done($row);
        $flow[0]['done'] = jeab_ordrar_is_booked($row) || $fxDone;
        $flow[] = [
            'key' => 'fakturering',
            'label' => 'Fakturering',
            'done' => $fxDone,
            'date' => jeab_ordrar_fakturering_date($row),
        ];
        return jeab_ordrar_mark_flow_current($flow);
    }
    if ($kind === 'sol' || $kind === 'batteri') {
        $booked = jeab_ordrar_is_booked($row);
        $bestalld = jeab_ordrar_bestalld($row);
        $stepDate = $bestalld ? jeab_ordrar_bestall_step_date($row) : '';
        $status = jeab_ordrar_bestallning_status($row);
        if ($status !== '' && $stepDate !== '') {
            $stepDate .= ' ' . $status;
        } elseif ($status !== '' && $bestalld) {
            $stepDate = $status;
        }
        $flow[] = ['key' => 'spec', 'label' => 'Föranmälan', 'done' => jeab_ordrar_foranmalan_wizard_done($row), 'date' => jeab_ordrar_spec_date($row)];
        $flow[] = ['key' => 'orderrader', 'label' => 'Beställ', 'done' => jeab_ordrar_orderrader_saved($row), 'date' => jeab_ordrar_orderrader_date($row)];
        $flow[] = ['key' => 'boka', 'label' => 'Boka', 'done' => $booked, 'date' => jeab_ordrar_mmdd(jeab_ordrar_install_start($row))];
        $flow[] = ['key' => 'bestall', 'label' => 'Beställ', 'done' => $bestalld, 'date' => $stepDate];
        $flow[] = ['key' => 'fakturering', 'label' => 'Fakturering', 'done' => jeab_ordrar_fakturering_done($row), 'date' => jeab_ordrar_fakturering_date($row)];
    }
    return jeab_ordrar_mark_flow_current($flow);
}

function jeab_ordrar_form_stops(array $row): array
{
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    $stops = ['status', 'bekrafta', 'eon'];
    if ($kind === 'laddbox') {
        if (jeab_ordrar_is_booked($row) || trim((string) ($row['fortnox_order'] ?? '')) !== '' || jeab_ordrar_has_invoice($row)) {
            $stops[] = 'fakturering';
        }
        return $stops;
    }
    if ($kind !== 'sol' && $kind !== 'batteri') {
        return $stops;
    }
    $stops[] = 'spec';
    if (trim((string) ($row['fortnox_order'] ?? '')) !== '') {
        $stops[] = 'fakturering';
    }
    if (!jeab_ordrar_spec_done($row)) {
        return $stops;
    }
    $stops[] = 'orderrader';
    if (!jeab_ordrar_orderrader_saved($row)) {
        return $stops;
    }
    $stops[] = 'boka';
    $stops[] = 'bestall';
    if (jeab_ordrar_bestalld($row) || jeab_ordrar_fakturering_done($row) || jeab_ordrar_is_booked($row)) {
        $stops[] = 'fakturering';
    }
    return $stops;
}

function jeab_ordrar_stop_clickable(array $row, string $key): bool
{
    return in_array($key, jeab_ordrar_form_stops($row), true);
}

function jeab_ordrar_default_stop(array $row): string
{
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    if ($kind === '') {
        return 'bekrafta';
    }
    if ($kind === 'laddbox') {
        if (!jeab_ordrar_is_booked($row) && !jeab_ordrar_fakturering_done($row)) {
            return 'bekrafta';
        }
        return 'fakturering';
    }
    if ($kind !== 'sol' && $kind !== 'batteri') {
        return 'bekrafta';
    }
    if (!jeab_ordrar_spec_done($row)) {
        return 'spec';
    }
    if (!jeab_ordrar_orderrader_saved($row)) {
        return 'orderrader';
    }
    if (!jeab_ordrar_bestalld($row)) {
        return 'bestall';
    }
    if (!jeab_ordrar_is_booked($row)) {
        return 'boka';
    }
    return 'fakturering';
}

function jeab_ordrar_legacy_stop(array $row, string $want): string
{
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    if (in_array($want, ['confirm', 'laddbox', 'paminelse', 'kop', 'vad'], true)) {
        return 'bekrafta';
    }
    if ($want === 'boka_elin') {
        if ($kind === 'laddbox' && (jeab_ordrar_is_booked($row) || jeab_ordrar_fakturering_done($row))) {
            return 'fakturering';
        }
        return 'bekrafta';
    }
    if ($want === 'bestallning') {
        return 'orderrader';
    }
    if (in_array($want, ['fakturera', 'fardiganmal', 'fortnox', 'fortnox_l'], true)) {
        return 'fakturering';
    }
    if ($want === 'boka_bestall') {
        if (!jeab_ordrar_is_booked($row)) {
            return 'boka';
        }
        if (!jeab_ordrar_bestalld($row)) {
            return 'bestall';
        }
        return 'fakturering';
    }
    return $want;
}

function jeab_ordrar_requested_stop(): string
{
    return trim((string) ($_GET['stop'] ?? ''));
}

/**
 * Panelen sidan landar på vid sidladdning. Utan stop landar ordern på
 * Bekräfta (Status ligger ovanför). Ett uttryckligt stop landar på sin panel,
 * stop=status landar högst upp. Tom sträng = ingen landning.
 *
 * @param list<string> $paneStops
 */
function jeab_ordrar_landing_pane(string $requested, string $visiblePane, array $paneStops): string
{
    $want = trim($requested) === '' ? 'bekrafta' : $visiblePane;
    if ($want === '' || $want === 'status' || !in_array($want, $paneStops, true)) {
        return '';
    }
    return $want;
}

function jeab_ordrar_view_stop(array $row, ?string $error = null): string
{
    $want = jeab_ordrar_legacy_stop($row, jeab_ordrar_requested_stop());
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    if (($want === 'boka' || $want === 'boka_elin') && (jeab_ordrar_is_booked($row) || (jeab_ordrar_bekrafta_slutfor($row) && jeab_ordrar_fakturering_done($row)))) {
        if (jeab_ordrar_bekrafta_slutfor($row) || jeab_ordrar_bestalld($row) || jeab_ordrar_fakturering_done($row)) {
            return 'fakturering';
        }
        return 'bestall';
    }
    if ($want === 'status') {
        return 'status';
    }
    if ($want === 'eon') {
        return 'eon';
    }
    if ($want !== '' && jeab_ordrar_stop_clickable($row, $want)) {
        return $want;
    }
    if (trim((string) ($row['fortnox_order'] ?? '')) === '' && jeab_ordrar_identity_missing($row) !== []) {
        return 'bekrafta';
    }
    if ($want === '') {
        return 'status';
    }
    if ($kind === '') {
        return 'bekrafta';
    }
    if ($kind === 'laddbox') {
        return jeab_ordrar_is_booked($row) || jeab_ordrar_fakturering_done($row) ? 'fakturering' : 'bekrafta';
    }
    if (is_string($error) && $error !== '' && preg_match('/fortnox|inaktiv|inte var aktiv|inactive/i', $error)) {
        if (jeab_ordrar_stop_clickable($row, 'fakturering')) {
            return 'fakturering';
        }
    }
    return jeab_ordrar_default_stop($row);
}

function jeab_ordrar_mmdd(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^\d{4}$/', $raw)) {
        return $raw;
    }
    try {
        $dt = new DateTimeImmutable($raw, new DateTimeZone('Europe/Stockholm'));
    } catch (Exception) {
        return '';
    }
    return $dt->format('md');
}

function jeab_ordrar_steps(array $row): array
{
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    $out = [];
    foreach (jeab_ordrar_step_labels() as $key => $_label) {
        $out[$key] = trim((string) ($steps[$key] ?? ''));
    }
    return $out;
}

function jeab_ordrar_install_start(array $row, ?JeabStore $store = null): string
{
    return jeab_ordrar_booking_times($row, $store)['starts_at'];
}

function jeab_ordrar_aprilic_leverans(array $row): string
{
    foreach ([
        'aprilic_leverans', 'aprilic_delivery', 'aprilice_leverans', 'aprilice_delivery',
        'leveransdatum', 'leverans_datum', 'bestall_leverans', 'delivery_date', 'deliveryDate',
        'leverans',
    ] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
        $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
        $value = trim((string) ($steps[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    foreach (is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [] as $line) {
        if (!is_array($line)) {
            continue;
        }
        $value = trim((string) ($line['leverans'] ?? $line['leveransdatum'] ?? $line['delivery'] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function jeab_ordrar_list_value(array $row, string $key, ?JeabStore $store = null): string
{
    if ($key === 'bokad_tid') {
        return jeab_ordrar_install_start($row, $store);
    }
    if ($key === 'foranmald') {
        return jeab_ordrar_foranmalan_date_raw($row);
    }
    if ($key === 'bestalld') {
        $actual = jeab_ordrar_actual_delivery($row);
        return $actual !== '' ? $actual : jeab_ordrar_onskad_leverans($row, $store);
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    return trim((string) ($steps[$key] ?? ''));
}

function jeab_ordrar_list_cell(array $row, string $key, ?JeabStore $store = null): string
{
    if ($key === 'foranmald') {
        return jeab_ordrar_foranmalan_step_date($row);
    }
    $date = jeab_ordrar_mmdd(jeab_ordrar_list_value($row, $key, $store));
    if ($key !== 'bestalld') {
        return $date;
    }
    $shop = jeab_ordrar_shop_label($row);
    if ($shop === '') {
        return $date;
    }
    return $date !== '' ? $date . ' · ' . $shop : $shop;
}

function jeab_ordrar_backfill_closed(array $row): bool
{
    return jeab_ordrar_allt_klart($row);
}

/**
 * Fyller bara tomt namn och tom reminder_created_at. Bokningstid och stängda ordrar rörs inte.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ordrar_apply_missing_names(array $row, array $reminder): array
{
    $hasBooking = array_key_exists('booking_id', $row);
    $bookingId = $row['booking_id'] ?? null;
    $hasSteps = array_key_exists('steps', $row);
    $steps = $row['steps'] ?? null;
    if (trim((string) ($row['name'] ?? '')) === '') {
        $name = jeab_purchase_reminder_name($reminder);
        if ($name !== '') {
            $row['name'] = $name;
            if (!is_array($row['applicant'] ?? null)) {
                $row['applicant'] = ['name' => '', 'email' => '', 'phone' => ''];
            }
            if (trim((string) ($row['applicant']['name'] ?? '')) === '') {
                $row['applicant']['name'] = $name;
            }
        }
    }
    if (trim((string) ($row['reminder_created_at'] ?? '')) === '') {
        $created = trim((string) ($reminder['created_at'] ?? $reminder['created'] ?? $reminder['createdAt'] ?? ''));
        if ($created !== '') {
            $row['reminder_created_at'] = $created;
        }
    }
    if ($hasBooking) {
        $row['booking_id'] = $bookingId;
    }
    if ($hasSteps) {
        $row['steps'] = $steps;
    }
    return $row;
}

/** @param array<string, mixed> $before @param array<string, mixed> $after */
function jeab_ordrar_names_changed(array $before, array $after): bool
{
    $beforeApplicant = is_array($before['applicant'] ?? null) ? (string) ($before['applicant']['name'] ?? '') : '';
    $afterApplicant = is_array($after['applicant'] ?? null) ? (string) ($after['applicant']['name'] ?? '') : '';
    return (string) ($before['name'] ?? '') !== (string) ($after['name'] ?? '')
        || (string) ($before['reminder_created_at'] ?? '') !== (string) ($after['reminder_created_at'] ?? '')
        || $beforeApplicant !== $afterApplicant;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ordrar_fill_missing_names(array $row, ?JeabStore $store = null): array
{
    if (!empty($GLOBALS['_JEAB_PURCHASE_IN_FILL']) || jeab_ordrar_backfill_closed($row)) {
        return $row;
    }
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        return $row;
    }
    if (trim((string) ($row['name'] ?? '')) !== '' && trim((string) ($row['reminder_created_at'] ?? '')) !== '') {
        return $row;
    }
    if (!$store instanceof JeabStore) {
        return $row;
    }
    $GLOBALS['_JEAB_PURCHASE_IN_FILL'] = true;
    try {
        $reminder = jeab_get_reminder($store, $id);
        if (!is_array($reminder)) {
            return $row;
        }
        return jeab_ordrar_apply_missing_names($row, $reminder);
    } catch (Throwable) {
        return $row;
    } finally {
        unset($GLOBALS['_JEAB_PURCHASE_IN_FILL']);
    }
}

/**
 * Skapar order för påminnelser som just blivit vunnen, eller för alla vunna som saknar order.
 * Befintliga ordrar, stängda rader och bokningstider lämnas. $onlyId begränsar till en påminnelse.
 *
 * @return array{checked:int,created:int,skipped:int}
 */
function jeab_ordrar_backfill_vunnen(JeabStore $store, ?string $company = null, bool $dry = false, ?int $onlyId = null): array
{
    $company = jeab_normalize_company($company ?? jeab_selected_company()) ?? 'jarfalla';
    $checked = 0;
    $created = 0;
    $skipped = 0;
    foreach (jeab_list_reminders($store, true, $company) as $reminder) {
        if (!is_array($reminder)) {
            continue;
        }
        if (jeab_reminder_company($reminder) !== $company) {
            continue;
        }
        $id = (int) ($reminder['id'] ?? 0);
        if ($onlyId !== null && $onlyId > 0 && $id !== $onlyId) {
            continue;
        }
        $tags = jeab_parse_tags($reminder['tags'] ?? []);
        if (!in_array('vunnen', $tags, true)) {
            continue;
        }
        $checked++;
        if ($id <= 0 || is_array(jeab_purchase_get($id)) || jeab_ordrar_deleted_blocks($id, $reminder)) {
            $skipped++;
            continue;
        }
        if ($dry) {
            $created++;
            continue;
        }
        try {
            jeab_purchase_start($store, $id, '');
            $created++;
        } catch (Throwable) {
            $skipped++;
        }
    }
    return ['checked' => $checked, 'created' => $created, 'skipped' => $skipped];
}

/**
 * Fyller saknat namn och skapad-datum på öppna ordrar, en rad i taget.
 *
 * @return array{checked:int,updated:int,skipped_closed:int}
 */
function jeab_ordrar_backfill_names(JeabStore $store, bool $dry = false, ?int $onlyId = null): array
{
    $checked = 0;
    $updated = 0;
    $skippedClosed = 0;
    foreach (jeab_purchase_all() as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? $key);
        if ($id <= 0) {
            continue;
        }
        if ($onlyId !== null && $onlyId > 0 && $id !== $onlyId) {
            continue;
        }
        $checked++;
        $needName = trim((string) ($row['name'] ?? '')) === '';
        $needCreated = trim((string) ($row['reminder_created_at'] ?? '')) === '';
        if (jeab_ordrar_backfill_closed($row)) {
            if ($needName || $needCreated) {
                $skippedClosed++;
            }
            continue;
        }
        if (!$needName && !$needCreated) {
            continue;
        }
        $reminder = jeab_get_reminder($store, $id);
        if (!is_array($reminder)) {
            continue;
        }
        $preview = jeab_ordrar_apply_missing_names($row, $reminder);
        if (!jeab_ordrar_names_changed($row, $preview)) {
            continue;
        }
        if ($dry) {
            $updated++;
            continue;
        }
        if (!function_exists('jeab_purchase_patch')) {
            continue;
        }
        $did = false;
        jeab_purchase_patch($id, function (array $fresh) use ($store, &$did): ?array {
            if (jeab_ordrar_backfill_closed($fresh)) {
                return null;
            }
            $liveId = (int) ($fresh['reminder_id'] ?? 0);
            $live = $liveId > 0 ? jeab_get_reminder($store, $liveId) : null;
            if (!is_array($live)) {
                return null;
            }
            $next = jeab_ordrar_apply_missing_names($fresh, $live);
            if (!jeab_ordrar_names_changed($fresh, $next)) {
                return null;
            }
            $did = true;
            return $next;
        });
        if ($did) {
            $updated++;
        }
    }
    return ['checked' => $checked, 'updated' => $updated, 'skipped_closed' => $skippedClosed];
}

/**
 * Läser ordrar där produkt, adress eller nätägare ritas tomma, eller där
 * contact_id saknas eller pekar på en kontakt som inte finns.
 * Skriver inget, även om dry=0 skickas med.
 *
 * @return list<array{id:int,empty:list<string>,contact:string}>
 */
function jeab_ordrar_blank_scan_rows(): array
{
    $out = [];
    foreach (jeab_purchase_all() as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? $key);
        if ($id <= 0) {
            continue;
        }
        $empty = [];
        if (function_exists('jeab_ordrar_v3_kind')) {
            if (jeab_ordrar_v3_kind($row) === '') {
                $empty[] = 'kind';
            }
        } elseif (trim((string) ($row['kind'] ?? '')) === '') {
            $empty[] = 'kind';
        }
        $place = function_exists('jeab_ordrar_board_place') ? trim(jeab_ordrar_board_place($row)) : '';
        $street = '';
        if (function_exists('jeab_ordrar_status_address_lines')) {
            $lines = jeab_ordrar_status_address_lines($row);
            $street = trim((string) ($lines['street'] ?? ''));
        }
        if ($place === '' && $street === '') {
            $empty[] = 'address';
        }
        // Sparad nätägare eller bolagets fasta förslag. Ingen geokodning.
        $nat = trim((string) ($row['natbolag'] ?? ''));
        if ($nat === '' && function_exists('jeab_ordrar_natbolag_address_fallback')) {
            $nat = trim(jeab_ordrar_natbolag_address_fallback($row));
        }
        if ($nat === '') {
            $empty[] = 'natagare';
        }
        $contactId = trim((string) ($row['contact_id'] ?? ''));
        $contact = '';
        if ($contactId === '') {
            $contact = 'missing';
        } elseif (function_exists('jeab_kontakt_get') && !is_array(jeab_kontakt_get($contactId))) {
            $contact = 'orphan';
        }
        if ($empty === [] && $contact === '') {
            continue;
        }
        $out[] = ['id' => $id, 'empty' => $empty, 'contact' => $contact];
    }
    usort($out, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);

    return $out;
}

/**
 * POST /hook.php
 * Authorization: Bearer <inkorg-token>
 * {"action":"ordrar.blank_scan"}
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_blank_scan(array $input = []): array
{
    $rows = jeab_ordrar_blank_scan_rows();

    return [
        'ok' => true,
        'action' => 'ordrar.blank_scan',
        'dry' => 1,
        'read_only' => 1,
        'ids' => array_map(static fn(array $row): int => (int) $row['id'], $rows),
        'rows' => $rows,
        'ignored_dry' => array_key_exists('dry', $input) && !jeab_ordrar_backfill_wants_dry($input) ? 1 : 0,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_blank_scan_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.blank_scan', 'status' => 401];
    }
    $result = jeab_ordrar_blank_scan($input);
    $result['status'] = 200;

    return $result;
}

function jeab_ordrar_backfill_wants_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];
    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

/**
 * Engångskörning över befintliga ordrar. dry=1 är standard och skriver inget.
 *
 * POST /hook.php
 * Authorization: Bearer <inkorg-token>
 * {"action":"ordrar.backfill"}
 * {"action":"ordrar.backfill","dry":0}
 * {"action":"ordrar.backfill","dry":0,"company":"jarfalla"}
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_backfill_run(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = jeab_ordrar_backfill_wants_dry($input);
    $company = strtolower(trim((string) ($input['company'] ?? '')));
    $companies = in_array($company, ['jarfalla', 'malmo'], true) ? [$company] : ['jarfalla', 'malmo'];
    $names = jeab_ordrar_backfill_names($store, $dry);
    $vunnen = ['checked' => 0, 'created' => 0, 'skipped' => 0];
    foreach ($companies as $co) {
        $part = jeab_ordrar_backfill_vunnen($store, $co, $dry);
        foreach (['checked', 'created', 'skipped'] as $key) {
            $vunnen[$key] += (int) ($part[$key] ?? 0);
        }
    }
    return [
        'ok' => true,
        'action' => 'ordrar.backfill',
        'dry' => $dry ? 1 : 0,
        'names' => $names,
        'vunnen' => $vunnen,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_backfill_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_ordrar_backfill_run($store, $input);
    $result['status'] = 200;
    return $result;
}

function jeab_ordrar_owner_audit_pdo(): ?PDO
{
    if (!function_exists('jeab_inbox_contact_set_email_audit_pdo')) {
        return null;
    }
    $pdo = jeab_inbox_contact_set_email_audit_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_ensure_order_owner_audit')) {
        return null;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    jeab_ensure_order_owner_audit($pdo, $driver === 'mysql' ? 'mysql' : 'sqlite');
    return $pdo;
}

function jeab_ordrar_owner_audit(int $id, string $owner): void
{
    $owner = trim($owner);
    if ($id <= 0 || $owner === '') {
        return;
    }
    $pdo = jeab_ordrar_owner_audit_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO order_owner_audit (created_at, reminder_id, owner) VALUES (?, ?, ?)')->execute([$at, $id, $owner]);
}

/** @return list<array<string, mixed>> */
function jeab_ordrar_owner_audit_list(): array
{
    $pdo = jeab_ordrar_owner_audit_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    $rows = $pdo->query('SELECT created_at, reminder_id, owner FROM order_owner_audit ORDER BY id')->fetchAll();
    return is_array($rows) ? $rows : [];
}

/**
 * Ordrar utan ägare, eller med en ägare som inte är påminnelsens ansvarige.
 * Bara id och föreslagen ägare. Ingen kundtext.
 *
 * @return list<array{id:int,owner:string}>
 */
function jeab_ordrar_owner_backfill_rows(JeabStore $store): array
{
    $rows = [];
    foreach (jeab_purchase_all() as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? $key);
        if ($id <= 0) {
            continue;
        }
        $reminder = jeab_get_reminder($store, $id);
        if (!is_array($reminder)) {
            continue;
        }
        $want = jeab_ordrar_reminder_assignee_names($reminder);
        if ($want === []) {
            continue;
        }
        $have = jeab_ordrar_stored_owner_names($row);
        if ($have !== [] && jeab_ordrar_owner_names_same($have, $want)) {
            continue;
        }
        $rows[] = ['id' => $id, 'owner' => implode(', ', $want)];
    }
    return $rows;
}

/**
 * dry=1 listar ordrar utan ägare eller med annan ägare än påminnelsens ansvarige.
 * dry=0 sätter ansvarig bara där ordern saknar ägare. Skriver ingen Fortnox-order.
 *
 * POST /hook.php
 * Authorization: Bearer <inkorg-token>
 * {"action":"order_owner_backfill","dry":1}
 * {"action":"order_owner_backfill","dry":0}
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_order_owner_backfill(JeabStore $store, array $input = []): array
{
    $dry = jeab_ordrar_backfill_wants_dry($input);
    $listed = jeab_ordrar_owner_backfill_rows($store);
    $fixed = 0;
    if (!$dry) {
        foreach ($listed as $item) {
            $id = (int) ($item['id'] ?? 0);
            $owner = (string) ($item['owner'] ?? '');
            if ($id <= 0 || $owner === '') {
                continue;
            }
            $did = false;
            jeab_purchase_patch($id, function (array $fresh) use ($store, &$did): ?array {
                if (jeab_ordrar_stored_owner_names($fresh) !== []) {
                    return null;
                }
                $liveId = (int) ($fresh['reminder_id'] ?? 0);
                $live = $liveId > 0 ? jeab_get_reminder($store, $liveId) : null;
                if (!is_array($live)) {
                    return null;
                }
                $next = jeab_ordrar_apply_reminder_owner($fresh, $live);
                if (jeab_ordrar_stored_owner_names($next) === []) {
                    return null;
                }
                $did = true;
                return $next;
            });
            if ($did) {
                $fixed++;
                jeab_ordrar_owner_audit($id, $owner);
            }
        }
    }
    return [
        'ok' => true,
        'action' => 'order_owner_backfill',
        'dry' => $dry ? 1 : 0,
        'rows' => $listed,
        'fixed' => $fixed,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_order_owner_backfill_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_ordrar_order_owner_backfill($store, $input);
    $result['status'] = 200;
    return $result;
}


/**
 * Svar till ordrar.lm_lookup. Personnummer lämnas aldrig med, bara om ett hittades.
 *
 * @param array<string, mixed> $suggest
 * @param list<mixed> $trace
 * @return array<string, mixed>
 */
function jeab_ordrar_lm_lookup_public(array $suggest, array $trace, bool $dry, int $orderId, bool $applied): array
{
    $fast = jeab_ordrar_format_beteckning((string) ($suggest['fastighetsbeteckning'] ?? ''));
    $count = 0;
    $pnrFound = false;
    $names = [];
    foreach (is_array($suggest['owners'] ?? null) ? $suggest['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        $hasPnr = trim((string) ($owner['personnummer'] ?? '')) !== '';
        if ($name === '' && !$hasPnr) {
            continue;
        }
        $count++;
        if ($hasPnr) {
            $pnrFound = true;
        }
        if ($name !== '') {
            $names[] = $owner;
        }
    }
    $names = jeab_ordrar_owner_names_public($names);
    $tried = [];
    foreach ($trace as $row) {
        if (!is_array($row)) {
            continue;
        }
        $query = trim((string) ($row['query'] ?? ''));
        if ($query !== '') {
            $tried[] = $query;
        }
    }
    $out = [
        'ok' => true,
        'action' => 'ordrar.lm_lookup',
        'dry' => $dry ? 1 : 0,
        'order_id' => $orderId,
        'query' => $tried[0] ?? '',
        'fallback_query' => $tried[1] ?? '',
        'fastighet_found' => $fast !== '',
        'fastighetsbeteckning' => $fast,
        'fetched_address' => trim((string) ($suggest['fetched_address'] ?? '')),
        'exact' => !empty($suggest['lookup_exact']) ? 1 : 0,
        'choice_count' => count(is_array($suggest['choices'] ?? null) ? $suggest['choices'] : []),
        'owner_count' => $count,
        'owners' => $names,
        'pnr_found' => $pnrFound,
        'applied' => $applied,
    ];
    return jeab_ordrar_lm_lookup_scrub($out);
}

/**
 * Namn (och andel) utan personnummer.
 *
 * @param list<mixed> $people
 * @return list<array<string, string>>
 */
function jeab_ordrar_owner_names_public(array $people): array
{
    $out = [];
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $pub = function_exists('jeab_fastighet_owner_public')
            ? jeab_fastighet_owner_public($person)
            : ['name' => trim((string) ($person['name'] ?? ''))];
        $name = trim((string) ($pub['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $row = ['name' => $name];
        $andel = trim((string) ($pub['andel'] ?? $person['andel'] ?? ''));
        if ($andel !== '') {
            $row['andel'] = $andel;
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * @param array<string, mixed> $out
 * @return array<string, mixed>
 */
function jeab_ordrar_lm_lookup_scrub(array $out): array
{
    $mask = static function (string $value): string {
        $masked = preg_replace('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', '[pnr]', $value) ?? $value;

        return preg_replace('/\d{6,}/', '[siffror]', $masked) ?? $masked;
    };
    $walk = static function (mixed $value) use (&$walk, $mask): mixed {
        if (is_string($value)) {
            return $mask($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['personnummer', 'pnr', 'owner_pnr', 'personnummer_finns', 'SocialSecurityNumber'], true)) {
                continue;
            }
            $clean[$key] = $walk($item);
        }

        return $clean;
    };
    $done = $walk($out);

    return is_array($done) ? $done : $out;
}

/**
 * Kör om Lantmäteriet-uppslaget för en order. dry=1 (standard) skriver inget.
 *
 * POST /hook.php
 * Authorization: Bearer <inkorg-token>
 * {"action":"ordrar.lm_lookup","order_id":123,"dry":1}
 * {"action":"ordrar.lm_lookup","order_id":123,"dry":0}
 *
 * order_id är samma id som i Ordrar (reminder_id).
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_lm_lookup_run(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = jeab_ordrar_backfill_wants_dry($input);
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange order_id', 'status' => 422];
    }
    $row = jeab_purchase_get($id);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'status' => 404];
    }
    $wasLive = jeab_ordrar_external_live();
    $hadBypass = array_key_exists('_JEAB_LM_CACHE_BYPASS', $GLOBALS);
    $wasBypass = $GLOBALS['_JEAB_LM_CACHE_BYPASS'] ?? null;
    jeab_ordrar_external_live(true);
    $forceBypass = !array_key_exists('bypass', $input)
        || !($input['bypass'] === 0 || $input['bypass'] === '0' || $input['bypass'] === false);
    if ($forceBypass) {
        $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = true;
    }
    $GLOBALS['_JEAB_LM_FRESH'] = true;
    try {
        $reminder = null;
        if (function_exists('jeab_ordrar_reminder_for_row')) {
            try {
                $got = jeab_ordrar_reminder_for_row($row);
                $reminder = is_array($got) ? $got : null;
            } catch (Throwable) {
                $reminder = null;
            }
        }
        $beforeFast = trim((string) ($row['fastighetsbeteckning'] ?? ''));
        $beforePnr = trim((string) ($row['personnummer'] ?? ''));
        $query = jeab_ordrar_lookup_query($reminder, $row);
        $suggest = jeab_ordrar_confirm_suggest($row, $reminder, $query !== '' ? $query : null, '', true);
        $applied = false;
        if (!$dry) {
            $GLOBALS['_JEAB_LM_OWNER_REPLACE'] = true;
            $updated = jeab_ordrar_sync_lookup_owners($row, $reminder, true);
            $applied = trim((string) ($updated['fastighetsbeteckning'] ?? '')) !== $beforeFast
                || trim((string) ($updated['personnummer'] ?? '')) !== $beforePnr
                || jeab_ordrar_owner_names_public(jeab_ordrar_people_list($updated['owners'] ?? [], 8)) !== jeab_ordrar_owner_names_public(jeab_ordrar_people_list($row['owners'] ?? [], 8));
        }
        $trace = $GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] ?? [];
        $out = jeab_ordrar_lm_lookup_public($suggest, is_array($trace) ? $trace : [], $dry, $id, $applied);
        $out['status'] = 200;
        return $out;
    } finally {
        jeab_ordrar_external_live($wasLive);
        unset($GLOBALS['_JEAB_LM_FRESH'], $GLOBALS['_JEAB_LM_OWNER_REPLACE']);
        if ($hadBypass) {
            $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = $wasBypass;
        } else {
            unset($GLOBALS['_JEAB_LM_CACHE_BYPASS']);
        }
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_lm_lookup_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    return jeab_ordrar_lm_lookup_run($store, $input);
}

/**
 * Om ett omhämt av Lantmäteriet skulle ske. Inget anrop. Inga namn eller personnummer.
 *
 * @return array{would_refetch:int,reason:string,order_owner_count:int,cache_owner_count:int}
 */
function jeab_ordrar_lm_refetch_would(array $row, ?array $reminder = null): array
{
    $query = jeab_ordrar_lookup_query($reminder, $row);
    $objektId = trim((string) ($row['objektidentitet'] ?? ''));
    $hit = ($query !== '' && function_exists('jeab_rot_lm_cache_get')) ? jeab_rot_lm_cache_get($query, $objektId) : null;
    $orderOwners = jeab_ordrar_people_list($row['owners'] ?? [], 8);
    $orderCount = count($orderOwners);
    $orderPnr = 0;
    foreach ($orderOwners as $owner) {
        if (trim((string) ($owner['personnummer'] ?? '')) !== '') {
            $orderPnr++;
        }
    }
    $cacheCount = is_array($hit) ? (int) ($hit['owner_count'] ?? 0) : 0;
    $reason = 'nej';
    $would = false;
    if (!is_array($hit)) {
        $would = true;
        $reason = 'saknar_cache';
    } elseif (function_exists('jeab_rot_lm_cache_suspect') && jeab_rot_lm_cache_suspect($hit)) {
        $would = true;
        $pnrCount = (int) ($hit['pnr_count'] ?? 0);
        $reason = ($cacheCount > 0 && $pnrCount < $cacheCount) ? 'saknar_personnummer' : 'agarantal';
    } elseif ($cacheCount !== $orderCount) {
        $would = true;
        $reason = 'agarantal';
    } elseif ($orderCount > 0 && $orderPnr < $orderCount) {
        $would = true;
        $reason = 'saknar_personnummer';
    }
    return [
        'would_refetch' => $would ? 1 : 0,
        'reason' => $reason,
        'order_owner_count' => $orderCount,
        'cache_owner_count' => $cacheCount,
    ];
}

/**
 * ordrar.lm_refetch. dry=1 (standard) säger bara om ett omhämt skulle ske.
 * dry=0 kräver confirm=SAM och gör exakt ett Lantmäteriet-anrop.
 * Svaret har antal, aldrig personnummer.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_lm_refetch(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = jeab_ordrar_backfill_wants_dry($input);
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange order_id', 'action' => 'ordrar.lm_refetch', 'status' => 422];
    }
    $row = jeab_purchase_get($id);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => 'ordrar.lm_refetch', 'status' => 404];
    }
    $reminder = null;
    if (function_exists('jeab_ordrar_reminder_for_row')) {
        try {
            $got = jeab_ordrar_reminder_for_row($row);
            $reminder = is_array($got) ? $got : null;
        } catch (Throwable) {
            $reminder = null;
        }
    }
    $would = jeab_ordrar_lm_refetch_would($row, $reminder);
    if ($dry) {
        $out = [
            'ok' => true,
            'action' => 'ordrar.lm_refetch',
            'dry' => 1,
            'order_id' => $id,
            'calls' => 0,
            'status' => 200,
        ] + $would;
        $scrubbed = jeab_ordrar_scrub_pnr($out);
        return is_array($scrubbed) ? $scrubbed : $out;
    }
    if (trim((string) ($input['confirm'] ?? '')) !== 'SAM') {
        return [
            'ok' => false,
            'error' => 'dry=0 kräver confirm=SAM',
            'action' => 'ordrar.lm_refetch',
            'dry' => 0,
            'order_id' => $id,
            'calls' => 0,
            'would_refetch' => $would['would_refetch'],
            'reason' => $would['reason'],
            'status' => 422,
        ];
    }
    $query = jeab_ordrar_lookup_query($reminder, $row);
    $objektId = trim((string) ($row['objektidentitet'] ?? ''));
    if (function_exists('jeab_rot_lm_cache_forget')) {
        jeab_rot_lm_cache_forget((string) ($row['fastighetsbeteckning'] ?? ''), $query, $objektId);
    }
    $beforeCalls = (int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0);
    $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = true;
    $GLOBALS['_JEAB_LM_CACHE_GEN'] = (int) ($GLOBALS['_JEAB_LM_CACHE_GEN'] ?? 0) + 1;
    $GLOBALS['_JEAB_LM_CALL_BUDGET'] = $beforeCalls + 1;
    $wasLive = jeab_ordrar_external_live();
    jeab_ordrar_external_live(true);
    try {
        $updated = jeab_ordrar_sync_lookup_owners($row, $reminder, true);
    } catch (Throwable $e) {
        if (function_exists('jeab_rot_lookup_log')) {
            jeab_rot_lookup_log('fel vid omhämt', get_class($e));
        }
        $updated = $row;
    } finally {
        jeab_ordrar_external_live($wasLive);
        unset($GLOBALS['_JEAB_LM_CACHE_BYPASS'], $GLOBALS['_JEAB_LM_CALL_BUDGET']);
    }
    $calls = (int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) - $beforeCalls;
    $owners = jeab_ordrar_people_list($updated['owners'] ?? [], 8);
    $pnrFound = false;
    foreach ($owners as $owner) {
        if (trim((string) ($owner['personnummer'] ?? '')) !== '') {
            $pnrFound = true;
            break;
        }
    }
    $out = [
        'ok' => true,
        'action' => 'ordrar.lm_refetch',
        'dry' => 0,
        'order_id' => $id,
        'calls' => $calls,
        'would_refetch' => 1,
        'reason' => 'omhamtad',
        'order_owner_count' => count($owners),
        'cache_owner_count' => count($owners),
        'pnr_found' => $pnrFound,
        'fastighet_found' => trim((string) ($updated['fastighetsbeteckning'] ?? '')) !== '',
        'status' => 200,
    ];
    $scrubbed = jeab_ordrar_scrub_pnr($out);
    return is_array($scrubbed) ? $scrubbed : $out;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_lm_refetch_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.lm_refetch', 'status' => 401];
    }
    return jeab_ordrar_lm_refetch($store, $input);
}

/**
 * Ny order från vunnen påminnelse: samma uppslag som ordrar.lm_lookup, dry=0.
 * Fel stoppar inte ordern. Texten till personalen innehåller inget personnummer.
 *
 * @return array<string, mixed>
 */
function jeab_ordrar_lm_lookup_on_create(JeabStore $store, array $row): array
{
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0 || !jeab_ordrar_external_live()) {
        return $row;
    }
    unset($GLOBALS['_JEAB_ROT_LOOKUP_FAILED']);
    try {
        $result = jeab_ordrar_lm_lookup_run($store, ['order_id' => $id, 'dry' => 0, 'bypass' => 0]);
    } catch (Throwable $e) {
        if (function_exists('jeab_rot_lookup_log')) {
            jeab_rot_lookup_log('fel vid uppslag när ordern skapades', get_class($e));
        }
        $result = ['ok' => false];
    }
    $failed = trim((string) ($GLOBALS['_JEAB_ROT_LOOKUP_FAILED'] ?? ''));
    unset($GLOBALS['_JEAB_ROT_LOOKUP_FAILED']);
    $fresh = jeab_purchase_get($id);
    if (!is_array($fresh)) {
        $fresh = $row;
    }
    if ($failed !== '' || empty($result['ok'])) {
        $fresh['lm_lookup_error'] = 'Lantmäteriet-uppslaget misslyckades. Fyll i fastighet, ägare och personnummer för hand.';
        $fresh['updated_at'] = jeab_now();
        return jeab_purchase_put($fresh);
    }
    if (isset($fresh['lm_lookup_error'])) {
        $fresh['lm_lookup_error'] = null;
        $fresh['updated_at'] = jeab_now();
        return jeab_purchase_put($fresh);
    }
    return $fresh;
}

/**
 * Gamla övre personnumret flyttas till huvudsökanden när ägarkortet saknar det.
 * dry=1 (standard) skriver inget. dry=0 kräver confirm=SAM.
 * Svaret har antal och order-id. Aldrig personnummer.
 *
 * POST /hook.php
 * Authorization: Bearer <inkorg-token>
 * {"action":"ordrar.pnr_owner_backfill","dry":1}
 * {"action":"ordrar.pnr_owner_backfill","dry":0,"confirm":"SAM"}
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_pnr_owner_backfill(JeabStore $store, array $input = []): array
{
    unset($store);
    $dry = jeab_ordrar_backfill_wants_dry($input);
    if (!$dry && trim((string) ($input['confirm'] ?? '')) !== 'SAM') {
        return [
            'ok' => false,
            'error' => 'dry=0 kräver confirm=SAM',
            'action' => 'ordrar.pnr_owner_backfill',
            'dry' => 0,
            'count' => 0,
            'order_ids' => [],
            'status' => 422,
        ];
    }
    $ids = [];
    foreach (jeab_purchase_all() as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? $key);
        if ($id <= 0 || !jeab_ordrar_pnr_owner_needs_move($row)) {
            continue;
        }
        $ids[] = $id;
        if ($dry) {
            continue;
        }
        $saved = jeab_purchase_patch($id, static function (array $fresh): ?array {
            if (!jeab_ordrar_pnr_owner_needs_move($fresh)) {
                return null;
            }
            return jeab_ordrar_pnr_owner_apply($fresh);
        });
        if (is_array($saved) && function_exists('jeab_contacts_sole_divert_property')) {
            try {
                jeab_contacts_sole_divert_property($saved);
            } catch (Throwable) {
            }
        }
    }
    $out = [
        'ok' => true,
        'action' => 'ordrar.pnr_owner_backfill',
        'dry' => $dry ? 1 : 0,
        'count' => count($ids),
        'order_ids' => $ids,
        'status' => 200,
    ];
    $scrubbed = jeab_ordrar_scrub_pnr($out);
    return is_array($scrubbed) ? $scrubbed : $out;
}

function jeab_ordrar_pnr_owner_needs_move(array $row): bool
{
    $legacy = jeab_ordrar_format_pnr((string) ($row['personnummer'] ?? ''));
    if ($legacy === '') {
        return false;
    }
    $owners = jeab_ordrar_people_list($row['owners'] ?? [], 8);
    $name = trim((string) ($row['huvudsokande'] ?? $row['name'] ?? ''));
    $idx = jeab_ordrar_huvud_owner_index($owners, $name);
    if ($idx === null) {
        foreach ($owners as $person) {
            if (jeab_ordrar_format_pnr((string) ($person['personnummer'] ?? '')) === $legacy) {
                return false;
            }
        }
        return true;
    }
    return jeab_ordrar_format_pnr((string) ($owners[$idx]['personnummer'] ?? '')) === '';
}

/** @param array<string, mixed> $row */
function jeab_ordrar_pnr_owner_apply(array $row): array
{
    $legacy = jeab_ordrar_format_pnr((string) ($row['personnummer'] ?? ''));
    if ($legacy === '') {
        return $row;
    }
    $owners = jeab_ordrar_people_list($row['owners'] ?? [], 8);
    $name = trim((string) ($row['huvudsokande'] ?? $row['name'] ?? ''));
    $idx = jeab_ordrar_huvud_owner_index($owners, $name);
    if ($idx === null) {
        array_unshift($owners, [
            'name' => $name,
            'personnummer' => $legacy,
            'address' => trim((string) ($row['huvudsokande_address'] ?? $row['address'] ?? '')),
            'andel' => '',
            'lagfart' => '',
        ]);
    } else {
        $owners[$idx]['personnummer'] = $legacy;
    }
    $row['owners'] = $owners;
    $row['personnummer'] = $legacy;
    $row['updated_at'] = jeab_now();
    return $row;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_pnr_owner_backfill_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    return jeab_ordrar_pnr_owner_backfill($store, $input);
}

/**
 * Visningsform utan klartext: 0701234705 och +46701234705 blir 070***705.
 * Tomt fält blir tom sträng. Kort eller utan siffror blir ***.
 */
function jeab_ordrar_mask_mobil(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($digits, '46') && strlen($digits) > 9) {
        $digits = '0' . substr($digits, 2);
    }
    if (strlen($digits) < 6) {
        return '***';
    }
    return substr($digits, 0, 3) . '***' . substr($digits, -3);
}

/** Orsak till auditen. Siffror som kan vara ett nummer tas bort. Tomt efter det är ogiltigt. */
function jeab_ordrar_set_mobil_reason(string $reason): string
{
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? $reason);
    $reason = preg_replace('/\+?\d[\d\s+\-]{4,}\d/', '[nummer]', $reason) ?? $reason;
    if (function_exists('mb_substr')) {
        $reason = mb_substr($reason, 0, 200, 'UTF-8');
    } else {
        $reason = substr($reason, 0, 200);
    }
    return trim($reason);
}

function jeab_ordrar_mobil_audit_pdo(): ?PDO
{
    if (!function_exists('jeab_inbox_contact_set_email_audit_pdo')) {
        return null;
    }
    $pdo = jeab_inbox_contact_set_email_audit_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_ensure_order_mobil_audit')) {
        return null;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    jeab_ensure_order_mobil_audit($pdo, $driver === 'mysql' ? 'mysql' : 'sqlite');
    return $pdo;
}

function jeab_ordrar_mobil_audit(int $id, string $company, string $reason, string $kind): void
{
    if ($id <= 0 || ($kind !== 'set' && $kind !== 'clear')) {
        return;
    }
    $pdo = jeab_ordrar_mobil_audit_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO order_mobil_audit (created_at, reminder_id, company, reason, change_kind) VALUES (?, ?, ?, ?, ?)'
    )->execute([$at, $id, $company, $reason, $kind]);
}

/** @return list<array<string, mixed>> */
function jeab_ordrar_mobil_audit_list(): array
{
    $pdo = jeab_ordrar_mobil_audit_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    $rows = $pdo->query('SELECT created_at, reminder_id, company, reason, change_kind FROM order_mobil_audit ORDER BY id')->fetchAll();
    return is_array($rows) ? $rows : [];
}

/**
 * Sätter eller tömmer mobilfältet på en order. dry=1 (standard) skriver inget.
 *
 * POST /hook.php
 * Authorization: Bearer <inkorg-token>
 * {"action":"ordrar.set_mobil","order_id":123,"co":"jarfalla","mobil":"0701234705","reason":"rättat nummer"}
 * {"action":"ordrar.set_mobil","order_id":123,"co":"malmo","mobil":"","reason":"ogiltigt nummer","dry":0}
 *
 * mobil="" tömmer. Annars krävs svenskt mobilformat (+467 och åtta siffror).
 * Svaret har bara maskerat nummer. Auditen har inget nummer.
 * Skriver bara phone (och applicant.phone när sökanden finns) via orderlagret.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_set_mobil_run(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = jeab_ordrar_backfill_wants_dry($input);
    $id = (int) ($input['order_id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange order_id', 'status' => 422];
    }
    $co = strtolower(trim((string) ($input['co'] ?? '')));
    if (!in_array($co, ['jarfalla', 'malmo'], true)) {
        return ['ok' => false, 'error' => 'ange co jarfalla eller malmo', 'status' => 422];
    }
    if (!array_key_exists('mobil', $input) || is_array($input['mobil'])) {
        return ['ok' => false, 'error' => 'ange mobil', 'status' => 422];
    }
    if (!array_key_exists('reason', $input) || !is_string($input['reason'])) {
        return ['ok' => false, 'error' => 'ange reason', 'status' => 422];
    }
    $reason = jeab_ordrar_set_mobil_reason($input['reason']);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'ange reason', 'status' => 422];
    }
    $row = jeab_purchase_get($id);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'status' => 404];
    }
    $rowCo = function_exists('jeab_normalize_company')
        ? (jeab_normalize_company($row['company'] ?? null) ?? '')
        : strtolower(trim((string) ($row['company'] ?? '')));
    if ($rowCo !== $co) {
        return ['ok' => false, 'error' => 'ordern tillhör inte bolaget', 'status' => 422];
    }
    $beforeRaw = trim((string) ($row['phone'] ?? ''));
    $raw = trim((string) $input['mobil']);
    if ($raw === '') {
        $nextPhone = '';
    } else {
        try {
            if (!function_exists('jeab_normalize_reminder_phone')) {
                return ['ok' => false, 'error' => 'Ange ett svenskt mobilnummer (07… eller +467…).', 'status' => 422];
            }
            $norm = jeab_normalize_reminder_phone($raw);
        } catch (InvalidArgumentException $e) {
            $message = trim($e->getMessage());
            return [
                'ok' => false,
                'error' => $message !== '' ? $message : 'Ange ett svenskt mobilnummer (07… eller +467…).',
                'status' => 422,
            ];
        }
        if (!is_string($norm) || $norm === '') {
            return ['ok' => false, 'error' => 'Ange ett svenskt mobilnummer (07… eller +467…).', 'status' => 422];
        }
        $nextPhone = $norm;
    }
    $changed = $nextPhone !== $beforeRaw;
    $written = false;
    if (!$dry && $changed) {
        if (jeab_ordrar_mobil_audit_pdo() === null) {
            return ['ok' => false, 'error' => 'auditen gick inte att spara', 'status' => 500];
        }
        $patched = jeab_purchase_patch($id, function (array $fresh) use ($co, $nextPhone): ?array {
            $freshCo = function_exists('jeab_normalize_company')
                ? (jeab_normalize_company($fresh['company'] ?? null) ?? '')
                : strtolower(trim((string) ($fresh['company'] ?? '')));
            if ($freshCo !== $co) {
                return null;
            }
            if (trim((string) ($fresh['phone'] ?? '')) === $nextPhone) {
                return null;
            }
            $fresh['phone'] = $nextPhone;
            if (is_array($fresh['applicant'] ?? null)) {
                $fresh['applicant']['phone'] = $nextPhone;
            }
            $fresh['updated_at'] = jeab_now();
            return $fresh;
        });
        $storedPhone = is_array($patched) ? trim((string) ($patched['phone'] ?? '')) : '';
        $soleOn = function_exists('jeab_contacts_sole') && jeab_contacts_sole();
        $contactId = is_array($patched) ? trim((string) ($patched['contact_id'] ?? '')) : '';
        if ($contactId === '') {
            $contactId = trim((string) ($row['contact_id'] ?? ''));
        }
        $linked = $soleOn && $contactId !== '' && function_exists('jeab_contacts_sole_linked_contact') && is_array(jeab_contacts_sole_linked_contact($contactId));
        if (!is_array($patched) || ($storedPhone !== $nextPhone && !$linked)) {
            return ['ok' => false, 'error' => 'mobilen skrevs inte', 'status' => 409];
        }
        jeab_ordrar_mobil_audit($id, $co, $reason, $nextPhone === '' ? 'clear' : 'set');
        $written = true;
        if ($soleOn) {
            try {
                $intended = $row;
                $intended['phone'] = $nextPhone;
                $intended['updated_at'] = jeab_now();
                if (is_array($intended['applicant'] ?? null)) {
                    $intended['applicant']['phone'] = $nextPhone;
                }
                if ($contactId === '' && function_exists('jeab_contacts_sole_inflow_upsert')) {
                    $made = jeab_contacts_sole_inflow_upsert([
                        'name' => (string) ($intended['huvudsokande'] ?? $intended['name'] ?? ''),
                        'email' => (string) ($intended['email'] ?? ''),
                        'phone' => $nextPhone,
                        'address' => (string) ($intended['address'] ?? ''),
                        'company' => $co,
                    ]);
                    $contactId = is_array($made) ? trim((string) ($made['id'] ?? '')) : '';
                    if ($contactId !== '' && function_exists('jeab_contacts_sole_inflow_link')) {
                        jeab_contacts_sole_inflow_link($store, $id, $contactId, true);
                        $intended['contact_id'] = $contactId;
                    }
                }
                if ($contactId !== '') {
                    $intended['contact_id'] = $contactId;
                    if (function_exists('jeab_contacts_sole_divert_order')) {
                        jeab_contacts_sole_divert_order($row, $intended);
                    }
                    $fresh = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
                    if (is_array($fresh) && function_exists('jeab_contacts_sole_order_should_strip') && jeab_contacts_sole_order_should_strip($fresh)) {
                        $dirty = false;
                        foreach (jeab_contacts_sole_order_duplicate_keys() as $key) {
                            if (trim((string) ($fresh[$key] ?? '')) !== '') {
                                $dirty = true;
                                break;
                            }
                        }
                        if ($dirty && function_exists('jeab_purchase_put')) {
                            jeab_purchase_put(jeab_contacts_sole_order_strip_customer($fresh));
                        }
                    }
                }
            } catch (Throwable) {
            }
        }
    }
    return [
        'ok' => true,
        'action' => 'ordrar.set_mobil',
        'dry' => $dry ? 1 : 0,
        'order_id' => $id,
        'co' => $co,
        'before' => jeab_ordrar_mask_mobil($beforeRaw),
        'after' => jeab_ordrar_mask_mobil($nextPhone),
        'cleared' => $nextPhone === '' ? 1 : 0,
        'written' => $written ? 1 : 0,
        'reason' => $reason,
        'status' => 200,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_set_mobil_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    return jeab_ordrar_set_mobil_run($store, $input);
}

function jeab_ordrar_list_labels(): array
{
    $labels = jeab_ordrar_step_labels();
    unset($labels['faktura_skapad']);
    return $labels;
}

function jeab_ordrar_is_klart(): bool
{
    return false;
}

function jeab_ordrar_invoice_number(array $row): string
{
    foreach (['fortnox_invoice', 'invoice_number', 'invoice', 'fortnox_invoice_number'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function jeab_ordrar_has_invoice(array $row): bool
{
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    if (trim((string) ($steps['faktura_skapad'] ?? '')) !== '') {
        return true;
    }
    return jeab_ordrar_invoice_number($row) !== '';
}


function jeab_ordrar_needs_reply(array $row): bool
{
    if (jeab_ordrar_allt_klart($row)) {
        return false;
    }
    foreach (jeab_ordrar_flow($row) as $st) {
        if (!is_array($st) || empty($st['current'])) {
            continue;
        }
        if (in_array((string) ($st['key'] ?? ''), ['spec', 'bekrafta', 'vad', 'laddbox'], true)) {
            return true;
        }
    }
    return false;
}

/**
 * Listans läge (Process-kolumnen) och v3:s steg delar källa för «stängd»:
 * jeab_ordrar_allt_klart (samma som v3 Stäng). Ett stängt ärende visas som Klart,
 * inte som första ofärdiga steg i det gamla flödet.
 */
function jeab_ordrar_process_view(array $row): array
{
    $flow = jeab_ordrar_flow($row);
    $total = max(1, count($flow));
    if (jeab_ordrar_allt_klart($row)) {
        foreach ($flow as $i => $st) {
            if (is_array($st)) {
                $flow[$i]['current'] = false;
            }
        }
        $inv = jeab_ordrar_invoice_number($row);
        return [
            'key' => 'klart',
            'label' => 'Klart',
            'idx' => $total,
            'total' => $total,
            'hint' => $inv !== '' ? 'faktura ' . $inv : 'stängt',
            'flow' => $flow,
            'closed' => true,
        ];
    }
    $done = 0;
    $idx = 0;
    $key = '';
    $label = '';
    foreach ($flow as $i => $st) {
        if (!is_array($st)) {
            continue;
        }
        if (!empty($st['done'])) {
            $done++;
        }
        if (!empty($st['current'])) {
            $idx = $i + 1;
            $key = (string) ($st['key'] ?? '');
            $label = (string) ($st['label'] ?? '');
        }
    }
    if ($idx === 0) {
        $idx = min($done + 1, $total);
        $key = (string) ($flow[$idx - 1]['key'] ?? '');
        $label = (string) ($flow[$idx - 1]['label'] ?? '');
    }
    $orderNo = trim((string) ($row['fortnox_order'] ?? ''));
    $label = match ($key) {
        'bekrafta', 'laddbox' => 'Bekräfta',
        'vad' => 'Vad/pris/anteckningar',
        'spec' => 'Föranmälan',
        'orderrader' => 'Beställ',
        'boka' => 'Boka',
        'bestall' => 'Beställ',
        'boka_bestall' => 'Boka + beställ',
        'fakturering', 'fardiganmal', 'fortnox', 'fortnox_l' => 'Fakturering',
        default => $label,
    };
    $fxHint = 'nästa: skapa order';
    if (jeab_ordrar_has_invoice($row)) {
        $fxHint = 'faktura skapad';
    } elseif ($orderNo !== '' && jeab_ordrar_fakturering_done($row)) {
        $fxHint = 'synkad';
    } elseif ($orderNo !== '') {
        $fxHint = 'nästa: skapa faktura';
    }
    $hint = match ($key) {
        'bekrafta' => jeab_ordrar_bekrafta_slutfor($row) ? 'nästa: boka' : 'väntar på kund',
        'vad' => 'nästa: pris och anteckning',
        'spec', 'laddbox' => 'nästa: komplettera underlag',
        'orderrader' => 'nästa: komplettera rader',
        'boka' => 'nästa: boka montage',
        'bestall' => 'nästa: leverans',
        'boka_bestall' => jeab_ordrar_is_booked($row) ? 'nästa: leveransvecka' : 'nästa: boka montage',
        'fakturering', 'fardiganmal', 'fortnox', 'fortnox_l' => $fxHint,
        default => '',
    };
    return [
        'key' => $key,
        'label' => $label,
        'idx' => $idx,
        'total' => $total,
        'hint' => $hint,
        'flow' => $flow,
    ];
}

function jeab_ordrar_foranmalan_track(array $row): array
{
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    if ($kind !== 'sol' && $kind !== 'batteri') {
        return ['key' => '', 'label' => '', 'tone' => ''];
    }
    $key = jeab_ordrar_foranmalan_case_status($row);
    $label = jeab_ordrar_foranmalan_case_label($row);
    $tone = match ($key) {
        'skickad' => 'info',
        'nekad' => 'danger',
        'godkand', 'fardiganmald' => 'ok',
        default => '',
    };
    return ['key' => $key, 'label' => $label, 'tone' => $tone];
}

function jeab_ordrar_besiktning_track(array $row): array
{
    if (jeab_ordrar_ui_kind($row['kind'] ?? '') !== 'sol') {
        return ['key' => '', 'label' => '', 'tone' => '', 'frac' => ''];
    }
    $raw = (string) ($row['solkoll_status'] ?? '');
    $key = function_exists('jeab_solkoll_status_key') ? (jeab_solkoll_status_key($raw) ?: trim($raw)) : trim($raw);
    $label = match ($key) {
        'ny' => 'Skapad',
        'paborjad' => 'Påbörjad',
        'slutford' => 'Slutförd',
        default => '',
    };
    $tone = match ($key) {
        'ny' => 'muted',
        'paborjad' => 'warn',
        'slutford' => 'ok',
        default => '',
    };
    $done = (int) ($row['solkoll_todos_done'] ?? 0);
    $total = (int) ($row['solkoll_todos_total'] ?? 0);
    $frac = ($key === 'paborjad' && $total > 0) ? ($done . '/' . $total) : '';
    return ['key' => $key, 'label' => $label, 'tone' => $tone, 'frac' => $frac];
}

function jeab_ordrar_board_date(array $row, ?JeabStore $store = null): array
{
    $bokad = jeab_ordrar_list_cell($row, 'bokad_tid', $store);
    $bestalld = jeab_ordrar_list_cell($row, 'bestalld', $store);
    return [
        'primary' => $bokad,
        'secondary' => $bestalld !== '' && $bestalld !== $bokad ? $bestalld : '',
    ];
}

function jeab_ordrar_board_product(array $row): string
{
    $product = trim((string) ($row['vad'] ?? $row['produkt'] ?? $row['product'] ?? ''));
    if ($product !== '') {
        return $product;
    }
    if (function_exists('jeab_ordrar_v3_kind') && jeab_ordrar_v3_kind($row) === 'servicejobb') {
        return 'Servicejobb';
    }
    $products = ['laddbox' => 'Elbilsladdare', 'batteri' => 'Batteri', 'sol' => 'Solpaneler'];
    return $products[jeab_ordrar_ui_kind($row['kind'] ?? '')] ?? '';
}

function jeab_ordrar_board_place(array $row): string
{
    if (function_exists('jeab_contacts_sole_view_for')) {
        $view = jeab_contacts_sole_view_for($row);
        if (is_array($view) && !empty($view['linked'])) {
            $street = trim((string) $view['street']);
            $city = trim((string) $view['city']);
            if ($street === '' && function_exists('jeab_contacts_sole_log_order_only')) {
                foreach (['huvudsokande_address', 'address', 'gata'] as $key) {
                    if (trim((string) ($row[$key] ?? '')) !== '') {
                        jeab_contacts_sole_log_order_only($row, 'address');
                        break;
                    }
                }
            }
            if ($street !== '' && $city !== '') {
                return $street . ', ' . $city;
            }

            return $street !== '' ? $street : $city;
        }
    }
    $raw = '';
    foreach (['huvudsokande_address', 'address', 'gata'] as $key) {
        $val = trim((string) ($row[$key] ?? ''));
        if ($val !== '') {
            $raw = $val;
            break;
        }
    }
    $name = jeab_ordrar_name($row);
    $street = $raw !== '' && function_exists('jeab_reminder_street') ? trim(jeab_reminder_street($raw)) : $raw;
    $city = $raw !== '' && function_exists('jeab_reminder_parse_city') ? trim(jeab_reminder_parse_city($raw)) : '';
    if ($city === '' || jeab_ordrar_looks_like_person_name($city, $name)) {
        $saved = trim((string) ($row['ort'] ?? ''));
        if ($saved !== '' && !jeab_ordrar_looks_like_person_name($saved, $name)) {
            $city = $saved;
        } elseif (jeab_ordrar_looks_like_person_name($city, $name)) {
            $city = '';
        }
    }
    if ($street !== '' && ($name !== '' && strcasecmp($street, $name) === 0 || jeab_ordrar_looks_like_person_name($street, $name))) {
        $street = '';
    }
    if ($street !== '' && $city !== '' && strcasecmp($street, $city) !== 0) {
        $place = $street . ', ' . $city;
    } elseif ($street !== '') {
        $place = $street;
    } else {
        $place = $city;
    }
    $place = trim($place);
    if ($place === '') {
        return '';
    }
    return preg_replace_callback('/[A-ZÅÄÖa-zåäö]+/u', static function (array $m): string {
        $word = $m[0];
        if (function_exists('mb_substr') && function_exists('mb_strtoupper') && function_exists('mb_strtolower')) {
            return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8')
                . mb_strtolower(mb_substr($word, 1, null, 'UTF-8'), 'UTF-8');
        }
        return strtoupper($word[0] ?? '') . strtolower(substr($word, 1));
    }, $place) ?? $place;
}

/** @return list<string> */
function jeab_ordrar_board_tags(array $row, ?JeabStore $store = null): array
{
    $cells = jeab_ordrar_lista_cells($row, $store);
    $filters = [];
    if (jeab_ordrar_needs_reply($row)) {
        $filters[] = 'wait';
    }
    if ((jeab_ordrar_foranmalan_track($row)['label'] ?? '') !== '' || ($cells['foran']['text'] ?? '') !== '') {
        $filters[] = 'foran';
    }
    if ((jeab_ordrar_besiktning_track($row)['label'] ?? '') !== '') {
        $filters[] = 'besikt';
    }
    if (in_array((string) ($cells['next']['key'] ?? ''), ['boka', 'bestall'], true)) {
        $filters[] = 'boka';
    }

    return $filters;
}

function jeab_ordrar_board_chip(string $chip): string
{
    $chip = strtolower(trim($chip));

    return in_array($chip, ['wait', 'foran', 'besikt', 'boka'], true) ? $chip : '';
}

function jeab_ordrar_board_matches_chip(array $row, string $chip, ?JeabStore $store = null): bool
{
    $chip = jeab_ordrar_board_chip($chip);
    if ($chip === '') {
        return true;
    }

    return in_array($chip, jeab_ordrar_board_tags($row, $store), true);
}

function jeab_ordrar_page_size(): int
{
    return 25;
}

/**
 * One chip and one search, then a slice. Offset is n/ns/nk, not a larger cap.
 *
 * @param list<array<string, mixed>> $rows
 * @return array{rows:list<array<string, mixed>>,total:int,next:?int,offset:int}
 */
function jeab_ordrar_board_page(array $rows, string $chip, string $query, int $offset, ?JeabStore $store = null, ?int $limit = null): array
{
    $limit = $limit ?? jeab_ordrar_page_size();
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $matched = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !jeab_ordrar_board_matches_chip($row, $chip, $store)) {
            continue;
        }
        $matched[] = $row;
    }
    if (trim($query) !== '') {
        $matched = jeab_ordrar_search_rows($matched, $query);
    }
    $total = count($matched);

    return [
        'rows' => array_slice($matched, $offset, $limit),
        'total' => $total,
        'next' => ($offset + $limit) < $total ? $offset + $limit : null,
        'offset' => $offset,
    ];
}

function jeab_ordrar_board_filters(array $rows, ?JeabStore $store = null): array
{
    $n = ['all' => 0, 'wait' => 0, 'foran' => 0, 'besikt' => 0, 'boka' => 0];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $n['all']++;
        foreach (jeab_ordrar_board_tags($row, $store) as $tag) {
            if (isset($n[$tag])) {
                $n[$tag]++;
            }
        }
    }
    return $n;
}

function jeab_ordrar_board_track_html(array $track): string
{
    $label = trim((string) ($track['label'] ?? ''));
    if ($label === '') {
        return '<span class="ordrar-track is-empty">—</span>';
    }
    $tone = trim((string) ($track['tone'] ?? ''));
    $frac = trim((string) ($track['frac'] ?? ''));
    $html = '<span class="ordrar-track' . ($tone !== '' ? ' is-' . e($tone) : '') . '">';
    $html .= '<i aria-hidden="true"></i>';
    $html .= '<span>' . e($label);
    if ($frac !== '') {
        $html .= ' ' . e($frac);
    }
    $html .= '</span></span>';
    return $html;
}

/**
 * Kundtext som listans sök matchar. Flaggan av: bara orderns kopia.
 * Flaggan på: kontaktens namn, gata, telefon och e-post, med kopian där kontakten är tom.
 *
 * @param array<string, mixed> $row
 */
function jeab_ordrar_search_text(array $row): string
{
    $sole = function_exists('jeab_contacts_sole') && jeab_contacts_sole();
    $source = $sole ? jeab_ordrar_display_row($row) : $row;
    $bits = [];
    foreach (['huvudsokande', 'name', 'customer_name', 'huvudsokande_address', 'address', 'gata', 'ort', 'phone', 'email', 'postnummer', 'postnr'] as $key) {
        $bit = trim((string) ($source[$key] ?? ''));
        if ($bit !== '' && !in_array($bit, $bits, true)) {
            $bits[] = $bit;
        }
    }
    if ($sole && function_exists('jeab_contacts_sole_linked_contact')) {
        $contact = jeab_contacts_sole_linked_contact(trim((string) ($row['contact_id'] ?? '')));
        if (is_array($contact)) {
            foreach (['street', 'street_key', 'street_key_column', 'owner_email', 'owner_phone', 'owner_name_fold'] as $key) {
                $bit = trim((string) ($contact[$key] ?? ''));
                if ($bit !== '' && !in_array($bit, $bits, true)) {
                    $bits[] = $bit;
                }
            }
        }
    }

    return implode(' ', $bits);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_search_rows(array $rows, string $query): array
{
    $query = trim($query);
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole()) {
        jeab_ordrar_list_warm_contacts($rows);
    }
    if ($query === '') {
        $all = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $all[] = $row;
            }
        }

        return $all;
    }
    $needle = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hay = jeab_ordrar_search_text($row);
        $hay = function_exists('mb_strtolower') ? mb_strtolower($hay, 'UTF-8') : strtolower($hay);
        if (str_contains($hay, $needle)) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * En fråga för sidans contact_id. Flaggan av gör inget.
 *
 * @param list<array<string, mixed>> $rows
 */
function jeab_ordrar_list_warm_contacts(array $rows): void
{
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole() || !function_exists('jeab_contacts_sole_load_ids')) {
        return;
    }
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['contact_id'] ?? ''));
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    jeab_contacts_sole_load_ids($ids);
}

function jeab_ordrar_row_cache_key(array $row, string $company, ?array $listFilter): string
{
    $bag = [
        (string) ($row['reminder_id'] ?? ''),
        (string) ($row['updated_at'] ?? ''),
        (string) ($row['status'] ?? ''),
        (string) ($row['kind'] ?? ''),
        (string) ($row['contact_id'] ?? ''),
        function_exists('jeab_ordrar_v3_row_stamp_hash') ? jeab_ordrar_v3_row_stamp_hash($row) : '',
        $company,
        is_array($listFilter) ? (string) ($listFilter['who'] ?? '') : '',
        is_array($listFilter) ? (string) ($listFilter['job'] ?? '') : '',
        is_array($listFilter) && !empty($listFilter['explicit']) ? '1' : '0',
        trim((string) ($_GET['q'] ?? '')),
    ];
    $json = json_encode($bag);

    return hash('sha256', is_string($json) ? $json : '');
}

function jeab_ordrar_row_cache_get(string $key): ?string
{
    $mem = $GLOBALS['_JEAB_ORDRAR_ROW_HTML'] ?? null;
    if (is_array($mem) && isset($mem[$key]) && is_string($mem[$key])) {
        return $mem[$key];
    }
    if (function_exists('apcu_fetch')) {
        $ok = false;
        $hit = apcu_fetch('jeab_orow_' . $key, $ok);
        if ($ok && is_string($hit) && $hit !== '') {
            return $hit;
        }
    }
    $file = jeab_ordrar_row_cache_file($key);
    if (is_file($file) && (time() - (int) filemtime($file)) < 120) {
        $hit = file_get_contents($file);
        if (is_string($hit) && $hit !== '') {
            return $hit;
        }
    }

    return null;
}

function jeab_ordrar_row_cache_file(string $key): string
{
    return rtrim(sys_get_temp_dir(), '/') . '/jeab-orow-' . substr($key, 0, 40);
}

function jeab_ordrar_row_cache_put(string $key, string $html): void
{
    if (!isset($GLOBALS['_JEAB_ORDRAR_ROW_HTML']) || !is_array($GLOBALS['_JEAB_ORDRAR_ROW_HTML'])) {
        $GLOBALS['_JEAB_ORDRAR_ROW_HTML'] = [];
    }
    $GLOBALS['_JEAB_ORDRAR_ROW_HTML'][$key] = $html;
    if (function_exists('apcu_store')) {
        apcu_store('jeab_orow_' . $key, $html, 120);
    }
    $file = jeab_ordrar_row_cache_file($key);
    if (file_put_contents($file, $html, LOCK_EX) !== false) {
        @chmod($file, 0600);
    }
}

function jeab_ordrar_board_row_html(array $row, string $company, ?JeabStore $store = null, ?array $listFilter = null): string
{
    $cacheKey = jeab_ordrar_row_cache_key($row, $company, $listFilter);
    $cached = jeab_ordrar_row_cache_get($cacheKey);
    if (is_string($cached)) {
        return $cached;
    }
    $html = jeab_ordrar_board_row_html_build($row, $company, $store, $listFilter);
    jeab_ordrar_row_cache_put($cacheKey, $html);

    return $html;
}

function jeab_ordrar_board_row_html_build(array $row, string $company, ?JeabStore $store = null, ?array $listFilter = null): string
{
    $row = jeab_ordrar_display_row($row);
    $hrefParams = ['co' => $company, 'id' => (int) ($row['reminder_id'] ?? 0)];
    // Explicit personval (t.ex. Alla) följer med in i ordern. Utan det blir
    // en omladdning inloggningens förval, Sam L, i stället för ordern.
    if (is_array($listFilter) && !empty($listFilter['explicit'])) {
        $who = trim((string) ($listFilter['who'] ?? ''));
        $job = trim((string) ($listFilter['job'] ?? ''));
        $hrefParams['who'] = $who !== '' ? $who : 'all';
        $hrefParams['job'] = $job !== '' ? $job : 'all';
    }
    $seek = trim((string) ($_GET['q'] ?? ''));
    if ($seek !== '') {
        $hrefParams['q'] = $seek;
    }
    $href = jeab_ordrar_url($hrefParams);
    $name = jeab_ordrar_name($row);
    $product = jeab_ordrar_board_product($row);
    $wait = jeab_ordrar_needs_reply($row);
    $cells = jeab_ordrar_lista_cells($row, $store);
    $progress = $cells['progress'];
    $next = $cells['next'];
    $nextKey = trim((string) ($next['key'] ?? ''));
    if ($nextKey !== '' && $nextKey !== 'klart') {
        $href .= '#steg-' . $nextKey;
    }
    $besikt = jeab_ordrar_besiktning_track($row);
    $filters = jeab_ordrar_board_tags($row, $store);
    $place = jeab_ordrar_board_place($row);
    $heading = $place !== '' ? $place : ($name !== '' ? $name : 'Order');
    $subParts = [];
    if ($product !== '') {
        $subParts[] = $product;
    }
    if ($name !== '' && $place !== '') {
        $subParts[] = $name;
    }
    $sub = implode(', ', $subParts);
    $searchBits = [$name, $place];
    foreach (['huvudsokande_address', 'address', 'gata', 'ort', 'huvudsokande', 'name', 'customer_name'] as $searchKey) {
        $bit = trim((string) ($row[$searchKey] ?? ''));
        if ($bit !== '' && !in_array($bit, $searchBits, true)) {
            $searchBits[] = $bit;
        }
    }
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole()) {
        foreach (['phone', 'email', 'postnummer', 'postnr'] as $searchKey) {
            $bit = trim((string) ($row[$searchKey] ?? ''));
            if ($bit !== '' && !in_array($bit, $searchBits, true)) {
                $searchBits[] = $bit;
            }
        }
        if (function_exists('jeab_contacts_sole_linked_contact')) {
            $contact = jeab_contacts_sole_linked_contact(trim((string) ($row['contact_id'] ?? '')));
            if (is_array($contact)) {
                foreach (['street_key', 'street_key_column', 'owner_email', 'owner_phone', 'owner_name_fold'] as $searchKey) {
                    $bit = trim((string) ($contact[$searchKey] ?? ''));
                    if ($bit !== '' && !in_array($bit, $searchBits, true)) {
                        $searchBits[] = $bit;
                    }
                }
            }
        }
    }
    $cls = 'ordrar-row' . ($wait ? ' is-wait' : '') . ($progress['closed'] ? ' is-closed' : '');
    $workerNames = function_exists('jeab_order_workers') ? jeab_order_workers($row) : [];
    $jobKey = function_exists('jeab_order_job') ? (jeab_order_job($row) ?? '') : '';
    $dash = '<span class="ordrar-cell-val is-empty">—</span>';
    $cell = static function (string $col, string $label, string $text, string $subText = '', string $extra = '', bool $subWait = false) use ($dash): string {
        $html = '<span class="ordrar-col ordrar-col-' . $col . '"><span class="ordrar-cell-lbl">' . e($label) . '</span>';
        $html .= $text !== '' ? '<span class="ordrar-cell-val' . $extra . '">' . e($text) . '</span>' : ($subText === '' ? $dash : '');
        if ($subText !== '') {
            $html .= '<span class="ordrar-cell-sub' . ($subWait ? ' is-wait' : '') . '">' . e($subText) . '</span>';
        }
        return $html . '</span>';
    };
    $html = '<li><a class="' . $cls . '" href="' . e($href) . '" data-name="' . e(trim(implode(' ', $searchBits))) . '" data-tags="' . e(implode(' ', $filters)) . '" data-workers="' . e(implode(', ', $workerNames)) . '" data-product="' . e($jobKey) . '" data-install="' . e((string) $cells['install_raw']) . '">';
    $html .= '<span class="ordrar-col ordrar-col-kund">';
    $html .= '<span class="ordrar-name">' . e($heading) . '</span>';
    if ($sub !== '') {
        $html .= '<span class="ordrar-product">' . e($sub) . '</span>';
    }
    if ($wait) {
        $html .= '<span class="ordrar-wait-lbl">väntar på följdfrågor</span>';
    }
    $html .= '</span>';
    // Steg: samma räkning som v3 (jeab_ordrar_v3_progress).
    $html .= '<span class="ordrar-col ordrar-col-process">';
    $html .= '<span class="ordrar-step-label">' . e($progress['label']) . '</span>';
    $html .= '<span class="ordrar-step-meta">' . jeab_ordrar_lista_bar_html($progress)
        . '<span class="ordrar-step-frac">' . (int) $progress['done'] . ' av ' . (int) $progress['total'] . '</span></span>';
    $html .= '</span>';
    $html .= $cell('foran', 'Föranmälan', (string) $cells['foran']['text'], (string) $cells['foran']['sub'], $cells['foran']['tone'] !== '' ? ' is-' . $cells['foran']['tone'] : '');
    $html .= '<span class="ordrar-col ordrar-col-besikt"><span class="ordrar-cell-lbl">Besiktning</span>' . jeab_ordrar_board_track_html($besikt) . '</span>';
    $html .= $cell('install', 'Installation', (string) $cells['install'], '', $cells['late'] ? ' is-late' : '');
    $html .= $cell('ordered', 'Beställd', (string) $cells['bestalld']['text'], (string) $cells['bestalld']['sub'], '', (bool) $cells['bestalld']['wait']);
    $html .= $cell('leverans', 'Leverans', (string) $cells['leverans']['text'], (string) $cells['leverans']['sub'], !empty($cells['leverans']['late']) ? ' is-late' : '');
    $html .= '<span class="ordrar-col ordrar-col-next">';
    if ($progress['closed']) {
        $html .= '<span class="ordrar-next is-wait">' . e($cells['closed_sub'] !== '' ? $cells['closed_sub'] : 'Stängt') . '</span>';
    } elseif ($next['text'] !== '') {
        $html .= '<span class="ordrar-next' . ($next['wait'] ? ' is-wait' : '') . '">' . e($next['text']) . '</span>';
    } else {
        $html .= $dash;
    }
    $html .= '</span></a></li>';
    return $html;
}

function jeab_ordrar_dashboard_status_html(array $row): string
{
    return jeab_ordrar_board_track_html(jeab_ordrar_foranmalan_track($row));
}

/**
 * Fält tavlan läser. Anteckning och råtext stannar i payload tills ordern öppnas.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ordrar_board_record(array $row): array
{
    $drop = [
        'install_note' => true,
        'install_note_rev' => true,
        'note' => true,
        'notes' => true,
        'lookup' => true,
        'lookup_raw' => true,
        'raw' => true,
        'html' => true,
        'body' => true,
        'message' => true,
        'pdf' => true,
        'base64' => true,
        // Tick-markör. Tavlan och list_json visar den inte.
        'ordrar_repair_hold' => true,
    ];
    $out = [];
    foreach ($row as $key => $value) {
        $name = (string) $key;
        if (isset($drop[$name]) || str_ends_with($name, '_raw') || str_ends_with($name, '_html') || str_ends_with($name, '_pdf') || str_ends_with($name, '_body')) {
            continue;
        }
        $out[$key] = $value;
    }
    $out['reminder_id'] = (int) ($row['reminder_id'] ?? 0);

    return $out;
}

/**
 * Samma rader som listan visade innan list_json: bolag, Allt klart och steg.
 *
 * @param list<array<string, mixed>> $source
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_visible_shape(array $source, string $company, bool $klart): array
{
    $rows = [];
    foreach ($source as $row) {
        if (!is_array($row)) {
            continue;
        }
        $steps = jeab_ordrar_steps($row);
        if (jeab_ordrar_allt_klart($row) !== $klart) {
            continue;
        }
        $rowCompany = jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
        if ($rowCompany !== $company) {
            continue;
        }
        // Behåll alla steg (spec, orderrader, fardiganmald …), inte bara de sju gamla etiketterna.
        // Annars räknade listan fram ett annat läge än ärendet och v3 (t.ex. «Föranmälan 2 av 6»
        // på en stängd order där steps.spec finns).
        $row['steps'] = array_merge(is_array($row['steps'] ?? null) ? $row['steps'] : [], $steps);
        $rows[] = $row;
    }
    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return $rows;
}

/** Stämpel för den formade listan. Tom sträng när SQL inte kan svara. */
function jeab_ordrar_visible_stamp(string $company, bool $klart): string
{
    if (!function_exists('jeab_purchases_sql_pdo')) {
        return '';
    }
    $pdo = jeab_purchases_sql_pdo();
    if (!$pdo instanceof PDO) {
        return '';
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $strict = function_exists('jeab_settings_value')
            && jeab_settings_value($pdo, $driver, 'purchases_list_index_v1') === 'ok';
        if ($strict) {
            $stmt = $pdo->prepare('SELECT COUNT(*), MAX(updated_at) FROM purchases WHERE company = ? AND klart = ?');
            $stmt->execute([$company, $klart ? 1 : 0]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*), MAX(updated_at) FROM purchases WHERE (klart = ? OR company IS NULL OR company = \'\')'
                . ' AND (company = ? OR company IS NULL OR company = \'\')'
            );
            $stmt->execute([$klart ? 1 : 0, $company]);
        }
        $row = $stmt->fetch(PDO::FETCH_NUM);
    } catch (Throwable) {
        return '';
    }
    if (!is_array($row)) {
        return '';
    }

    $mode = $strict ? 's' : 'l';

    return $mode . '|' . $company . '|' . ($klart ? '1' : '0') . '|' . (int) ($row[0] ?? 0) . '|' . (string) ($row[1] ?? '');
}

function jeab_ordrar_visible_cache_file(string $stamp): string
{
    return sys_get_temp_dir() . '/jeab-ordrar-vis-' . hash('sha256', $stamp) . '.ser';
}

/** @return list<array<string, mixed>>|null */
function jeab_ordrar_visible_cache_read(string $stamp): ?array
{
    if ($stamp === '') {
        return null;
    }
    $path = jeab_ordrar_visible_cache_file($stamp);
    // Stämpeln (antal + senaste updated_at) byts när en order skrivs.
    // Filen får ligga kvar tills dess, högst en kvart, så ett missat skriv inte fastnar.
    if (!is_file($path) || filemtime($path) < time() - 900) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $rows = @unserialize($raw, ['allowed_classes' => false]);

    return is_array($rows) ? $rows : null;
}

/** @param list<array<string, mixed>> $rows */
function jeab_ordrar_visible_cache_write(string $stamp, array $rows): void
{
    if ($stamp === '') {
        return;
    }
    $path = jeab_ordrar_visible_cache_file($stamp);
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, serialize($rows), LOCK_EX) === false) {
        return;
    }
    @rename($tmp, $path);
}

function jeab_ordrar_list_cache_forget(): void
{
    $GLOBALS['_JEAB_ORDRAR_VISIBLE'] = [];
    foreach (glob(sys_get_temp_dir() . '/jeab-ordrar-vis-*.ser') ?: [] as $file) {
        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }
}

function jeab_ordrar_visible_cached(string $company, bool $klart, ?string $job): ?array
{
    $cacheKey = $company . '|' . ($klart ? '1' : '0') . '|' . ($job ?? '');
    if (isset($GLOBALS['_JEAB_ORDRAR_VISIBLE'][$cacheKey]) && is_array($GLOBALS['_JEAB_ORDRAR_VISIBLE'][$cacheKey])) {
        return $GLOBALS['_JEAB_ORDRAR_VISIBLE'][$cacheKey];
    }
    $stamp = jeab_ordrar_visible_stamp($company, $klart);
    if ($job !== null && $job !== '') {
        $stamp .= '|' . $job;
    }
    $cached = jeab_ordrar_visible_cache_read($stamp);
    if (!is_array($cached)) {
        return null;
    }
    if (!isset($GLOBALS['_JEAB_ORDRAR_VISIBLE']) || !is_array($GLOBALS['_JEAB_ORDRAR_VISIBLE'])) {
        $GLOBALS['_JEAB_ORDRAR_VISIBLE'] = [];
    }
    $GLOBALS['_JEAB_ORDRAR_VISIBLE'][$cacheKey] = $cached;
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('ordrar_cache', 0.0);
    }

    return $cached;
}

function jeab_ordrar_visible(?string $company = null, bool $klart = false, ?string $job = null): array
{
    $company = jeab_normalize_company($company ?? jeab_selected_company()) ?? 'jarfalla';
    $job = $job !== null && function_exists('jeab_normalize_job_key') ? jeab_normalize_job_key($job) : null;
    $cached = jeab_ordrar_visible_cached($company, $klart, $job);
    if (is_array($cached)) {
        return $cached;
    }
    $source = function_exists('jeab_purchase_list')
        ? jeab_purchase_list($company, $klart, null, 0, $job)
        : jeab_purchase_all();
    $rows = jeab_ordrar_visible_shape(is_array($source) ? $source : [], $company, $klart);
    if ($job !== null && $job !== '') {
        $rows = jeab_ordrar_filter_list($rows, null, $job);
    }
    $cacheKey = $company . '|' . ($klart ? '1' : '0') . '|' . ($job ?? '');
    $stamp = jeab_ordrar_visible_stamp($company, $klart);
    if ($job !== null && $job !== '') {
        $stamp .= '|' . $job;
    }
    jeab_ordrar_visible_cache_write($stamp, $rows);
    if (!isset($GLOBALS['_JEAB_ORDRAR_VISIBLE']) || !is_array($GLOBALS['_JEAB_ORDRAR_VISIBLE'])) {
        $GLOBALS['_JEAB_ORDRAR_VISIBLE'] = [];
    }
    $GLOBALS['_JEAB_ORDRAR_VISIBLE'][$cacheKey] = $rows;
    return $rows;
}

/**
 * Allt klart utan person, produkt, chip eller sök. Ordningen är created_at,
 * så sidan kan skäras i SQL. Null betyder att hela urvalet ska läsas.
 *
 * @return array{rows:list<array<string, mixed>>,total:int}|null
 */
function jeab_ordrar_klart_sql_window(string $company, int $offset, int $limit): ?array
{
    if ($limit < 1 || !function_exists('jeab_purchases_sql_pdo') || !function_exists('jeab_sql_purchases_list_count')) {
        return null;
    }
    $pdo = jeab_purchases_sql_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_value') || !function_exists('jeab_purchase_list')) {
        return null;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (jeab_settings_value($pdo, $driver, 'purchases_list_index_v1') !== 'ok') {
        return null;
    }
    if (jeab_settings_value($pdo, $driver, 'purchases_list_json_v1') !== 'ok') {
        return null;
    }
    $company = jeab_normalize_company($company) ?? 'jarfalla';
    $total = jeab_sql_purchases_list_count($pdo, $company, true);
    if (function_exists('jeab_sql_purchases_has_list_skip') && jeab_sql_purchases_has_list_skip($pdo, $company, true)) {
        return null;
    }
    $offset = max(0, $offset);
    if ($total === 0 || $offset >= $total) {
        return ['rows' => [], 'total' => $total];
    }
    $raw = jeab_purchase_list($company, true, $limit, $offset);
    if (!is_array($raw)) {
        return null;
    }
    $shaped = jeab_ordrar_visible_shape($raw, $company, true);
    $rawN = 0;
    foreach ($raw as $row) {
        if (is_array($row)) {
            $rawN++;
        }
    }
    $expect = min($limit, max(0, $total - $offset));
    if ($rawN !== $expect || count($shaped) !== $rawN) {
        return null;
    }

    return ['rows' => $shaped, 'total' => $total];
}

/**
 * Tavlan: öppna ordrar läses som list_json (hela urvalet, för sortering,
 * Slutfas och räknare). Allt klart skärs i SQL när filtret är tomt.
 *
 * @return array{
 *   rows:list<array<string, mixed>>,
 *   slutfasRows:list<array<string, mixed>>,
 *   klartRows:list<array<string, mixed>>,
 *   otherRows:list<array<string, mixed>>,
 *   boardFilters:array<string, int>,
 *   ordrarTotal:int,
 *   slutfasTotal:int,
 *   klartTotal:int,
 *   ordrarNext:?int,
 *   slutfasNext:?int,
 *   klartNext:?int
 * }
 */
function jeab_ordrar_board_collect(
    string $company,
    ?string $who,
    ?string $job,
    string $chip,
    string $query,
    int $actionOffset,
    int $slutfasOffset,
    int $klartOffset,
    ?JeabStore $store = null
): array {
    $company = jeab_normalize_company($company) ?? 'jarfalla';
    $chip = jeab_ordrar_board_chip($chip);
    $query = trim($query);
    $actionOffset = max(0, $actionOffset);
    $slutfasOffset = max(0, $slutfasOffset);
    $klartOffset = max(0, $klartOffset);
    $pageSize = jeab_ordrar_page_size();
    $klartPageSize = 40;
    $openVisible = jeab_ordrar_visible($company, false, $job);
    if (function_exists('jeab_ordrar_list_warm_contacts')) {
        jeab_ordrar_list_warm_contacts($openVisible);
    }
    $klartWindow = null;
    if ($who === null && $job === null && $chip === '' && $query === '') {
        $klartWindow = jeab_ordrar_klart_sql_window($company, max(0, $klartOffset), $klartPageSize);
    }
    $klartVisible = is_array($klartWindow)
        ? $klartWindow['rows']
        : jeab_ordrar_visible($company, true, $job);
    if ($store instanceof JeabStore && function_exists('jeab_bookings_warm')) {
        $warmIds = [];
        foreach (array_merge($openVisible, $klartVisible) as $warmRow) {
            if (is_array($warmRow)) {
                $warmIds[] = (int) ($warmRow['booking_id'] ?? 0);
            }
        }
        jeab_bookings_warm($store, $warmIds);
    }
    $scoped = jeab_ordrar_lista_sort(jeab_ordrar_filter_list($openVisible, $who, $job), $store);
    $klartAll = is_array($klartWindow)
        ? $klartWindow['rows']
        : jeab_ordrar_filter_list($klartVisible, $who, $job);
    $boardFilters = jeab_ordrar_board_filters($scoped, $store);
    $actionAll = [];
    $slutfasAll = [];
    foreach ($scoped as $scopedRow) {
        if (!is_array($scopedRow)) {
            continue;
        }
        if (jeab_ordrar_is_slutfas($scopedRow, $store)) {
            $slutfasAll[] = $scopedRow;
        } else {
            $actionAll[] = $scopedRow;
        }
    }
    $actionPage = jeab_ordrar_board_page($actionAll, $chip, $query, $actionOffset, $store);
    $slutfasPage = jeab_ordrar_board_page($slutfasAll, $chip, $query, $slutfasOffset, $store);
    $emptyKlartFilter = $who === null && $job === null && $chip === '' && $query === '';
    if (is_array($klartWindow)) {
        $klartRows = $klartAll;
        $klartTotal = (int) $klartWindow['total'];
        $klartNext = ($klartOffset + $klartPageSize) < $klartTotal ? $klartOffset + $klartPageSize : null;
    } else {
        $klartPage = jeab_ordrar_board_page($klartAll, $chip, $query, $klartOffset, $store, $emptyKlartFilter ? $klartPageSize : null);
        $klartRows = $klartPage['rows'];
        $klartTotal = $klartPage['total'];
        $klartNext = $klartPage['next'];
    }
    $rows = $actionPage['rows'];
    $slutfasRows = $slutfasPage['rows'];
    $otherRows = [];
    if ($actionPage['total'] === 0 && $slutfasPage['total'] === 0 && $klartTotal === 0 && ($who !== null || $job !== null)) {
        $ownIds = [];
        foreach (array_merge($actionAll, $slutfasAll, $klartAll) as $r) {
            if (is_array($r)) {
                $ownIds[(string) ($r['reminder_id'] ?? '')] = true;
            }
        }
        $foreign = [];
        foreach (array_merge(jeab_ordrar_lista_sort(jeab_ordrar_visible($company, false), $store), jeab_ordrar_visible($company, true)) as $r) {
            if (is_array($r) && !isset($ownIds[(string) ($r['reminder_id'] ?? '')])) {
                $foreign[] = $r;
            }
        }
        $otherPage = jeab_ordrar_board_page($foreign, $chip, $query, 0, $store);
        $otherRows = $otherPage['rows'];
    }

    return [
        'rows' => $rows,
        'slutfasRows' => $slutfasRows,
        'klartRows' => $klartRows,
        'otherRows' => $otherRows,
        'boardFilters' => $boardFilters,
        'ordrarTotal' => $actionPage['total'],
        'slutfasTotal' => $slutfasPage['total'],
        'klartTotal' => $klartTotal,
        'ordrarNext' => $actionPage['next'],
        'slutfasNext' => $slutfasPage['next'],
        'klartNext' => $klartNext,
    ];
}

function jeab_ordrar_activity_list(array $row): array
{
    $raw = $row['activity'] ?? $row['log'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $text = trim((string) ($item['text'] ?? $item['label'] ?? ''));
        if ($text === '') {
            continue;
        }
        $entry = [
            'at' => trim((string) ($item['at'] ?? $item['when'] ?? '')),
            'text' => $text,
        ];
        $key = trim((string) ($item['key'] ?? ''));
        if ($key !== '') {
            $entry['key'] = $key;
        }
        $out[] = $entry;
    }
    return $out;
}

function jeab_ordrar_activity_append(array $row, string $text, string $at = '', string $key = '', bool $save = true): array
{
    $text = trim($text);
    if ($text === '') {
        return $row;
    }
    $at = trim($at);
    if ($at === '') {
        $at = function_exists('jeab_now') ? jeab_now() : (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d H:i:s');
    }
    $list = jeab_ordrar_activity_list($row);
    if ($key !== '') {
        foreach ($list as $item) {
            if (($item['key'] ?? '') === $key) {
                return $row;
            }
        }
    }
    $entry = ['at' => $at, 'text' => $text];
    if ($key !== '') {
        $entry['key'] = $key;
    }
    $list[] = $entry;
    $row['activity'] = $list;
    $row['log'] = $list;
    if (!$save || (int) ($row['reminder_id'] ?? 0) <= 0) {
        return $row;
    }
    $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : $at;
    return jeab_purchase_put($row);
}

function jeab_ordrar_activity_booking_sent(int $bookingId): array
{
    $sms = '';
    $email = '';
    if ($bookingId <= 0) {
        return ['sms_at' => '', 'email_at' => ''];
    }
    if (function_exists('jeab_sms_sent_store')) {
        $store = jeab_sms_sent_store();
        $sent = $store[(string) $bookingId] ?? [];
        if (is_array($sent)) {
            $sms = trim((string) ($sent['sms_at'] ?? ''));
            $email = trim((string) ($sent['email_at'] ?? ''));
        }
    }
    return ['sms_at' => $sms, 'email_at' => $email];
}

function jeab_ordrar_activity_backfill(array $row, bool $save = true): array
{
    $before = count(jeab_ordrar_activity_list($row));
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    $foranmald = trim((string) ($steps['foranmald'] ?? ''));
    if ($foranmald !== '') {
        $row = jeab_ordrar_activity_append($row, 'Föranmälan skickad', $foranmald, 'foranmald', false);
    }
    $bookingId = (int) ($row['booking_id'] ?? 0);
    if ($bookingId > 0) {
        $times = jeab_ordrar_activity_booking_sent($bookingId);
        if ($times['sms_at'] !== '') {
            $row = jeab_ordrar_activity_append($row, 'SMS skickat', $times['sms_at'], 'sms:' . $bookingId, false);
        }
        if ($times['email_at'] !== '') {
            $row = jeab_ordrar_activity_append($row, 'Mejl skickat', $times['email_at'], 'mail:' . $bookingId, false);
        }
        if (function_exists('jeab_calendar_log_read')) {
            foreach (jeab_calendar_log_read()['entries'] as $ev) {
                if (!is_array($ev) || (int) ($ev['booking_id'] ?? 0) !== $bookingId) {
                    continue;
                }
                $kind = (string) ($ev['type'] ?? '');
                $at = trim((string) ($ev['at'] ?? ''));
                if ($at === '') {
                    continue;
                }
                if ($kind === 'sms') {
                    $row = jeab_ordrar_activity_append($row, 'SMS skickat', $at, 'sms:' . $bookingId, false);
                }
                if ($kind === 'mail') {
                    $row = jeab_ordrar_activity_append($row, 'Mejl skickat', $at, 'mail:' . $bookingId, false);
                }
            }
        }
    }
    $list = jeab_ordrar_activity_list($row);
    if (count($list) === $before) {
        return $row;
    }
    $row['activity'] = $list;
    $row['log'] = $list;
    if (!$save || (int) ($row['reminder_id'] ?? 0) <= 0) {
        return $row;
    }
    $id = (int) $row['reminder_id'];
    if (!function_exists('jeab_purchase_patch')) {
        $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d H:i:s');

        return jeab_purchase_put($row);
    }
    // Låst rad, inte den kopia som renderingen hade. Visningen behåller sina övriga fält.
    $saved = jeab_purchase_patch($id, static function (array $current): ?array {
        $next = jeab_ordrar_activity_backfill($current, false);
        if (count(jeab_ordrar_activity_list($next)) === count(jeab_ordrar_activity_list($current))) {
            return null;
        }
        $next['updated_at'] = function_exists('jeab_now') ? jeab_now() : (string) ($next['updated_at'] ?? '');

        return $next;
    });
    if (!is_array($saved)) {
        return $row;
    }
    $row['activity'] = $saved['activity'] ?? $row['activity'];
    $row['log'] = $saved['log'] ?? $row['log'];
    $row['updated_at'] = $saved['updated_at'] ?? ($row['updated_at'] ?? '');

    return $row;
}

function jeab_ordrar_activity_when(string $at): string
{
    $at = trim($at);
    if ($at === '') {
        return '';
    }
    $dateOnly = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $at);
    try {
        $dt = new DateTimeImmutable($at, new DateTimeZone('Europe/Stockholm'));
    } catch (Exception) {
        return $at;
    }
    $months = [
        1 => 'jan', 2 => 'feb', 3 => 'mar', 4 => 'apr', 5 => 'maj', 6 => 'jun',
        7 => 'jul', 8 => 'aug', 9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec',
    ];
    $day = (int) $dt->format('j');
    $mon = $months[(int) $dt->format('n')] ?? $dt->format('M');
    if ($dateOnly) {
        return $day . ' ' . $mon;
    }
    return $day . ' ' . $mon . ' ' . $dt->format('H:i');
}

function jeab_ordrar_activity_sorted(array $row): array
{
    $list = jeab_ordrar_activity_list($row);
    usort($list, static function (array $a, array $b): int {
        return strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? ''));
    });
    return $list;
}

function jeab_ordrar_activity_html(array $row): string
{
    $list = jeab_ordrar_activity_sorted($row);
    $html = '<section class="ordrar-log"><h2 class="ordrar-log-head">Aktivitet</h2><ol>';
    foreach ($list as $item) {
        $when = jeab_ordrar_activity_when((string) ($item['at'] ?? ''));
        $text = (string) ($item['text'] ?? '');
        $html .= '<li class="ordrar-log-row"><time class="ordrar-log-when">'
            . htmlspecialchars($when, ENT_QUOTES, 'UTF-8')
            . '</time>  <span class="ordrar-log-text">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . '</span></li>';
    }
    $html .= '</ol></section>';
    return $html;
}

function jeab_ordrar_positive_money(mixed $value): int
{
    if (!function_exists('jeab_purchase_amount')) {
        return 0;
    }
    $amount = (int) round(jeab_purchase_amount($value));

    return $amount > 0 ? $amount : 0;
}

/** Ett ensamt belopp i en offerttext. En NEO-trappa är flera belopp och ger 0. */
function jeab_ordrar_note_offer_price(string $note): int
{
    $note = str_replace(["\u{00a0}", "\u{202f}"], ' ', $note);
    $labeled = [];
    $pattern = '/(?:pris efter grön teknik|offertpris|att betala|kundpris|pris)\s*[:=]?\s*([\d\s]+)\s*kr/iu';
    if (preg_match_all($pattern, $note, $matches) === false) {
        return 0;
    }
    foreach ($matches[1] ?? [] as $raw) {
        $amount = jeab_ordrar_positive_money($raw);
        if ($amount > 0) {
            $labeled[$amount] = true;
        }
    }
    if (count($labeled) > 1) {
        return 0;
    }
    if (count($labeled) === 1) {
        return (int) array_key_first($labeled);
    }
    $pairs = [];
    if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*kWh\s+([\d\s]+)\s*kr/iu', $note, $kwh) !== false) {
        foreach ($kwh[2] ?? [] as $raw) {
            $amount = jeab_ordrar_positive_money($raw);
            if ($amount > 0) {
                $pairs[$amount] = true;
            }
        }
    }
    if (count($pairs) === 1) {
        return (int) array_key_first($pairs);
    }

    return 0;
}

/**
 * Uttryckligt offertpris på påminnelsen. Tomt när bara en prislista står i texten.
 *
 * @param array<string, mixed> $reminder
 * @return array{amount:int,source:string}
 */
function jeab_ordrar_reminder_offer_price(array $reminder): array
{
    $keys = ['offertpris', 'offert_pris', 'quote_price', 'pris', 'att_betala', 'kundpris', 'price'];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $reminder) || is_array($reminder[$key])) {
            continue;
        }
        $amount = jeab_ordrar_positive_money($reminder[$key]);
        if ($amount > 0) {
            return ['amount' => $amount, 'source' => 'paminnelse.' . $key];
        }
    }
    foreach (['offer', 'offert', 'quote'] as $nest) {
        $box = $reminder[$nest] ?? null;
        if (!is_array($box)) {
            continue;
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $box) || is_array($box[$key])) {
                continue;
            }
            $amount = jeab_ordrar_positive_money($box[$key]);
            if ($amount > 0) {
                return ['amount' => $amount, 'source' => 'paminnelse.' . $nest . '.' . $key];
            }
        }
    }
    $note = trim((string) ($reminder['note'] ?? ''));
    if ($note === '' && is_string($reminder['notes'] ?? null)) {
        $note = trim((string) $reminder['notes']);
    }
    $fromNote = $note === '' ? 0 : jeab_ordrar_note_offer_price($note);
    if ($fromNote > 0) {
        return ['amount' => $fromNote, 'source' => 'paminnelse.offert'];
    }

    return ['amount' => 0, 'source' => ''];
}

/** @param array<string, mixed> $kalkyl */
function jeab_ordrar_kalkyl_has_choice(array $kalkyl): bool
{
    if (trim((string) ($kalkyl['sigen_typ'] ?? '')) !== '') {
        return true;
    }
    if (function_exists('jeab_offert_num') && (int) round(jeab_offert_num($kalkyl['b14'] ?? 0)) > 0) {
        return true;
    }
    $b3 = function_exists('jeab_offert_num') ? (int) round(jeab_offert_num($kalkyl['b3'] ?? 0)) : (int) ($kalkyl['b3'] ?? 0);
    if ($b3 > 0 && trim((string) ($kalkyl['sigen_kw'] ?? '')) !== '') {
        return true;
    }
    foreach (['att_betala', 'kundpris', 'pris', 'i14'] as $key) {
        if (jeab_ordrar_positive_money($kalkyl[$key] ?? 0) > 0) {
            return true;
        }
    }

    return false;
}

/** Sparat kalkylbelopp, annars omräkning när ett val finns. Tom kalkyl ger 0. */
function jeab_ordrar_kalkyl_att_betala_amount(array $row, string $kind): int
{
    $kalkyl = is_array($row['kalkyl'] ?? null) ? $row['kalkyl'] : [];
    if (!jeab_ordrar_kalkyl_has_choice($kalkyl)) {
        return 0;
    }
    foreach (['att_betala', 'kundpris', 'pris'] as $key) {
        $stored = jeab_ordrar_positive_money($kalkyl[$key] ?? 0);
        if ($stored > 0) {
            return $stored;
        }
    }
    $kind = jeab_ordrar_ui_kind($kind);
    if ($kind !== 'batteri' && $kind !== 'sol') {
        return 0;
    }
    if (!function_exists('jeab_offert_calc') || !function_exists('jeab_offert_kalkyl_input') || !function_exists('jeab_offert_kalkyl_att_betala')) {
        return 0;
    }
    try {
        $calc = jeab_offert_calc(jeab_offert_kalkyl_input($row, $kind));
        $amount = (int) jeab_offert_kalkyl_att_betala($calc, $kind);
    } catch (Throwable) {
        return 0;
    }

    return $amount > 0 ? $amount : 0;
}

/**
 * @param array<string, mixed> $row
 * @return array{amount:int,source:string}
 */
function jeab_ordrar_kalkyl_link_price(array $row, ?array $reminder): array
{
    $urls = [];
    $sources = [$row];
    if (is_array($reminder)) {
        $sources[] = $reminder;
    }
    foreach ($sources as $src) {
        foreach (['kalkyl_lank', 'kalkyl_url', 'kalkyl_link', 'link', 'url'] as $key) {
            $value = trim((string) ($src[$key] ?? ''));
            if ($value !== '' && (stripos($value, 'kalkyl') !== false || str_contains($value, 'http'))) {
                $urls[] = $value;
            }
        }
        $note = (string) ($src['note'] ?? '');
        if (preg_match_all('#https?://\S*kalkyl\S*#iu', $note, $found) !== false) {
            foreach ($found[0] ?? [] as $url) {
                $urls[] = rtrim((string) $url, '.,)');
            }
        }
    }
    foreach ($urls as $url) {
        $query = [];
        $parts = parse_url($url);
        if (is_array($parts) && isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        foreach (['pris', 'kundpris', 'att_betala', 'price'] as $key) {
            $amount = jeab_ordrar_positive_money($query[$key] ?? 0);
            if ($amount > 0) {
                return ['amount' => $amount, 'source' => 'kalkyl_lank'];
            }
        }
        $kwh = function_exists('jeab_offert_num') ? jeab_offert_num($query['kwh'] ?? 0) : (float) ($query['kwh'] ?? 0);
        if ($kwh > 0 && function_exists('jeab_offert_neo_kundpris')) {
            $price = jeab_offert_neo_kundpris($kwh / 7.5);
            if (is_int($price) && $price > 0) {
                return ['amount' => $price, 'source' => 'kalkyl_lank'];
            }
        }
    }

    return ['amount' => 0, 'source' => ''];
}

/**
 * @param array<string, mixed> $row
 * @return array{amount:int,source:string}
 */
function jeab_ordrar_neo_price_from_kwh(array $row, ?array $reminder): array
{
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    if ($kind === '' && is_array($reminder)) {
        $kind = jeab_ordrar_reminder_product($reminder);
    }
    if ($kind !== 'batteri' || !function_exists('jeab_offert_neo_kundpris')) {
        return ['amount' => 0, 'source' => ''];
    }
    $kwh = 0.0;
    $sources = [$row];
    if (is_array($reminder)) {
        $sources[] = $reminder;
    }
    foreach ($sources as $src) {
        foreach (['kwh', 'storlek_kwh', 'batteri_kwh'] as $key) {
            $value = function_exists('jeab_offert_num') ? jeab_offert_num($src[$key] ?? 0) : (float) ($src[$key] ?? 0);
            if ($value > 0) {
                $kwh = $value;
                break 2;
            }
        }
    }
    $modules = 0.0;
    if ($kwh > 0) {
        $modules = $kwh / 7.5;
    } else {
        $kalkyl = is_array($row['kalkyl'] ?? null) ? $row['kalkyl'] : [];
        if ($kalkyl === [] && is_array($reminder) && is_array($reminder['kalkyl'] ?? null)) {
            $kalkyl = $reminder['kalkyl'];
        }
        $b3 = function_exists('jeab_offert_num') ? (int) round(jeab_offert_num($kalkyl['b3'] ?? 0)) : (int) ($kalkyl['b3'] ?? 0);
        if ($b3 > 0) {
            $modules = (float) $b3;
        }
    }
    if ($modules <= 0) {
        return ['amount' => 0, 'source' => ''];
    }
    $price = jeab_offert_neo_kundpris($modules);

    return is_int($price) && $price > 0 ? ['amount' => $price, 'source' => 'neo'] : ['amount' => 0, 'source' => ''];
}

/**
 * Kalkyl på ordern, sedan påminnelsen, sedan kalkylänk, sedan NEO från känd kWh.
 *
 * @param array<string, mixed> $row
 * @return array{amount:int,source:string}
 */
function jeab_ordrar_kalkyl_price_candidate(array $row, ?array $reminder): array
{
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    if ($kind === '' && is_array($reminder)) {
        $kind = jeab_ordrar_reminder_product($reminder);
    }
    $orderAmount = jeab_ordrar_kalkyl_att_betala_amount($row, $kind);
    if ($orderAmount > 0) {
        return ['amount' => $orderAmount, 'source' => 'kalkyl'];
    }
    if (is_array($reminder)) {
        $fromReminder = $reminder;
        if (jeab_ordrar_ui_kind($fromReminder['kind'] ?? '') === '' && $kind !== '') {
            $fromReminder['kind'] = $kind;
        }
        $reminderAmount = jeab_ordrar_kalkyl_att_betala_amount($fromReminder, $kind);
        if ($reminderAmount > 0) {
            return ['amount' => $reminderAmount, 'source' => 'kalkyl'];
        }
    }
    $link = jeab_ordrar_kalkyl_link_price($row, $reminder);
    if ((int) ($link['amount'] ?? 0) > 0) {
        return $link;
    }

    return jeab_ordrar_neo_price_from_kwh($row, $reminder);
}

/**
 * Offertpris på påminnelsen, annars kalkyl, annars inget.
 *
 * @param array<string, mixed> $row
 * @return array{amount:int,source:string}
 */
function jeab_ordrar_price_candidate(array $row, ?array $reminder): array
{
    if (is_array($reminder)) {
        $offer = jeab_ordrar_reminder_offer_price($reminder);
        if ((int) ($offer['amount'] ?? 0) > 0) {
            return $offer;
        }
    }
    $kalkyl = jeab_ordrar_kalkyl_price_candidate($row, $reminder);
    if ((int) ($kalkyl['amount'] ?? 0) > 0) {
        return $kalkyl;
    }

    return ['amount' => 0, 'source' => ''];
}

/**
 * Fyller pris och kundpris när båda är tomma. Ett ifyllt belopp lämnas.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ordrar_apply_carried_price(array $row, ?array $reminder): array
{
    if (jeab_ordrar_positive_money($row['pris'] ?? 0) > 0 || jeab_ordrar_positive_money($row['kundpris'] ?? 0) > 0) {
        return $row;
    }
    $candidate = jeab_ordrar_price_candidate($row, $reminder);
    $amount = (int) ($candidate['amount'] ?? 0);
    if ($amount <= 0) {
        return $row;
    }
    $row['pris'] = $amount;
    $row['kundpris'] = $amount;

    return $row;
}

/**
 * Det som gör «Skapa order och boka» grå. Samma ord som knappen visar.
 *
 * @return list<string>
 */
function jeab_ordrar_bekrafta_ui_missing(array $row): array
{
    $missing = [];
    $pnr = function_exists('jeab_ordrar_fortnox_submit_personnummer')
        ? jeab_ordrar_fortnox_submit_personnummer($row)
        : jeab_ordrar_canonical_personnummer($row);
    if ($pnr === '') {
        $missing[] = 'personnummer';
    }
    if (trim((string) ($row['fastighetsbeteckning'] ?? '')) === '') {
        $missing[] = 'fastighetsbeteckning';
    }
    if (trim((string) ($row['huvudsokande'] ?? '')) === '') {
        $missing[] = 'huvudsökande';
    }
    $huvud = trim((string) ($row['huvudsokande'] ?? ''));
    foreach (jeab_ordrar_other_owners(jeab_ordrar_people_list($row['medsokande'] ?? [], 8), $huvud) as $med) {
        if (trim((string) ($med['name'] ?? '')) !== '' && trim((string) ($med['personnummer'] ?? '')) === '') {
            $missing[] = 'personnummer för medsökande';
            break;
        }
    }
    $amount = function_exists('jeab_ordrar_att_betala_amount')
        ? jeab_ordrar_att_betala_amount($row)
        : jeab_ordrar_positive_money($row['pris'] ?? 0);
    if ($amount <= 0) {
        $missing[] = 'pris';
    }

    return $missing;
}

function jeab_ordrar_bekrafta_block_message(array $row): string
{
    if (jeab_ordrar_ui_kind($row['kind'] ?? '') === '') {
        return 'Välj produkt.';
    }
    $missing = jeab_ordrar_bekrafta_ui_missing($row);
    if ($missing === []) {
        return '';
    }

    return 'Saknas för Fortnox-order: ' . implode(', ', $missing) . '.';
}

/**
 * Skriver tom fastighet, saknade ägare och tomt pris från Lantmäteriet och offerten.
 * Ifyllda fält lämnas. Inget Fortnox-anrop.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $suggest
 * @return array<string, mixed>
 */
function jeab_ordrar_bekrafta_apply_carry(array $row, array $suggest, ?array $reminder): array
{
    $next = $row;
    $changed = false;
    $fast = trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
    if ($fast !== '' && trim((string) ($next['fastighetsbeteckning'] ?? '')) === '' && empty($suggest['lookup_empty'])) {
        $next['fastighetsbeteckning'] = $fast;
        $changed = true;
    }
    $stored = count(jeab_ordrar_people_list(array_merge(
        is_array($next['owners'] ?? null) ? $next['owners'] : [],
        is_array($next['medsokande'] ?? null) ? $next['medsokande'] : []
    ), 8));
    $lmOwners = is_array($suggest['owners'] ?? null) ? $suggest['owners'] : [];
    $lmCount = count(jeab_ordrar_people_list($lmOwners, 8));
    if ($lmCount > $stored && empty($suggest['lookup_empty'])) {
        $query = function_exists('jeab_ordrar_lookup_query') ? jeab_ordrar_lookup_query($reminder, $next) : '';
        $filled = jeab_ordrar_apply_lookup_owners($next, $suggest, $query);
        $next = $filled;
        $changed = true;
    }
    if (jeab_ordrar_canonical_personnummer($next) === '' && trim((string) ($suggest['personnummer'] ?? '')) !== '') {
        $incoming = jeab_ordrar_format_pnr((string) $suggest['personnummer']);
        $current = jeab_ordrar_format_pnr((string) ($next['personnummer'] ?? ''));
        // Samma nummer som redan står på ordern ska inte räknas som en ändring.
        // Annars sparas hela visningsraden, inklusive faktura och stängd status.
        if ($incoming === '' || $incoming !== $current) {
            $next['personnummer'] = (string) $suggest['personnummer'];
            $changed = true;
        }
    }
    $priced = jeab_ordrar_apply_carried_price($next, $reminder);
    if ((int) ($priced['pris'] ?? 0) !== (int) ($next['pris'] ?? 0) || (int) ($priced['kundpris'] ?? 0) !== (int) ($next['kundpris'] ?? 0)) {
        $next = $priced;
        $changed = true;
    }
    if (!$changed) {
        return $row;
    }
    $id = (int) ($next['reminder_id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_purchase_put')) {
        return $next;
    }
    $next['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');

    return jeab_ordrar_put_leaving_note($next);
}

/**
 * Ett uppslag. Fyller ordern när beteckning, ägare eller pris saknas.
 *
 * @param array<string, mixed> $row
 * @return array{row:array<string,mixed>,suggest:array<string,mixed>}
 */
function jeab_ordrar_bekrafta_prepare(array $row, ?array $reminder): array
{
    // Priset skrivs före uppslaget. En vanlig sidvisning stänger av externa
    // anrop, och ett uppslag som hänger eller kastar får inte lämna priset tomt.
    $row = jeab_ordrar_bekrafta_apply_carry($row, [
        'lookup_empty' => true,
        'owners' => [],
        'fastighetsbeteckning' => '',
    ], $reminder);
    $wasLive = jeab_ordrar_external_live();
    // Vanlig öppning har redan slagit av externa anrop. Tvinga inte på
    // Lantmäteriet och cache-förbigång: det är sekunderna i orderkortet.
    if (!$wasLive) {
        // Sidan blockerar Graph och kalkyl. Bekräfta får ändå läsa den lokala
        // Lantmäteriet-klassen, samma spärrflagga som den vanliga öppningen.
        $GLOBALS['_JEAB_BEKRAFTA_LOOKUP'] = true;
        try {
            $suggest = jeab_ordrar_confirm_suggest($row, is_array($reminder) ? $reminder : null);
        } catch (Throwable) {
            $suggest = ['lookup_empty' => true, 'owners' => [], 'fastighetsbeteckning' => ''];
        } finally {
            unset($GLOBALS['_JEAB_BEKRAFTA_LOOKUP']);
        }
        $next = jeab_ordrar_bekrafta_apply_carry($row, is_array($suggest) ? $suggest : [], $reminder);

        return ['row' => $next, 'suggest' => is_array($suggest) ? $suggest : []];
    }
    $hadBypass = array_key_exists('_JEAB_LM_CACHE_BYPASS', $GLOBALS);
    $wasBypass = $GLOBALS['_JEAB_LM_CACHE_BYPASS'] ?? null;
    jeab_ordrar_external_live(true);
    $GLOBALS['_JEAB_BEKRAFTA_LOOKUP'] = true;
    $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = true;
    try {
        $suggest = jeab_ordrar_confirm_suggest($row, is_array($reminder) ? $reminder : null);
    } catch (Throwable) {
        $suggest = ['lookup_empty' => true, 'owners' => [], 'fastighetsbeteckning' => ''];
    } finally {
        jeab_ordrar_external_live($wasLive);
        unset($GLOBALS['_JEAB_BEKRAFTA_LOOKUP']);
        if ($hadBypass) {
            $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = $wasBypass;
        } else {
            unset($GLOBALS['_JEAB_LM_CACHE_BYPASS']);
        }
    }
    $next = jeab_ordrar_bekrafta_apply_carry($row, is_array($suggest) ? $suggest : [], $reminder);

    return ['row' => $next, 'suggest' => is_array($suggest) ? $suggest : []];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ordrar_bekrafta_lookup(array $row, ?array $reminder, bool $fresh = false): array
{
    if (!function_exists('jeab_fastighet_lookup')) {
        return [];
    }
    try {
        $suggest = jeab_fastighet_lookup($row, $reminder, '', $fresh);
    } catch (Throwable) {
        return [];
    }

    return is_array($suggest) ? $suggest : [];
}

/** @param array<string, mixed> $row */
function jeab_ordrar_bekrafta_pnr_present(array $row, ?array $reminder, array $suggest): array
{
    if (jeab_ordrar_canonical_personnummer($row) !== '') {
        return ['candidate' => 'finns', 'source' => 'order'];
    }
    if (is_array($reminder)) {
        foreach (['personnummer', 'pnr'] as $key) {
            if (trim((string) ($reminder[$key] ?? '')) !== '') {
                return ['candidate' => 'finns', 'source' => 'paminnelse'];
            }
        }
    }
    $people = function_exists('jeab_fastighet_suggest_people') ? jeab_fastighet_suggest_people($suggest) : [];
    foreach ($people as $person) {
        if (is_array($person) && trim((string) ($person['personnummer'] ?? '')) !== '') {
            return ['candidate' => 'finns', 'source' => 'lantmateriet'];
        }
    }

    return ['candidate' => '', 'source' => ''];
}

/**
 * ordrar.bekrafta_blockers. Alltid torr. Varje grindrad med kandidat och källa.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_bekrafta_blockers(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange order_id', 'action' => 'ordrar.bekrafta_blockers', 'dry' => 1, 'status' => 422];
    }
    $row = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => 'ordrar.bekrafta_blockers', 'order_id' => $id, 'dry' => 1, 'status' => 404];
    }
    $reminder = null;
    if (function_exists('jeab_ordrar_reminder_for_row')) {
        try {
            $got = jeab_ordrar_reminder_for_row($row);
            $reminder = is_array($got) ? $got : null;
        } catch (Throwable) {
            $reminder = null;
        }
    }
    $suggest = jeab_ordrar_bekrafta_lookup($row, $reminder);
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    $productCandidate = $kind;
    $productSource = $kind !== '' ? 'order' : '';
    if ($productCandidate === '' && is_array($reminder)) {
        $productCandidate = jeab_ordrar_reminder_product($reminder);
        $productSource = $productCandidate !== '' ? 'paminnelse' : '';
    }
    $pnr = jeab_ordrar_bekrafta_pnr_present($row, $reminder, $suggest);
    $storedFast = trim((string) ($row['fastighetsbeteckning'] ?? ''));
    $lmFast = function_exists('jeab_ordrar_format_beteckning')
        ? jeab_ordrar_format_beteckning((string) ($suggest['fastighetsbeteckning'] ?? ''))
        : trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
    $fastCandidate = $storedFast !== '' ? $storedFast : $lmFast;
    $fastSource = $storedFast !== '' ? 'order' : ($lmFast !== '' ? 'lantmateriet' : '');
    $storedName = trim((string) ($row['huvudsokande'] ?? ''));
    $lmName = trim((string) ($suggest['huvudsokande'] ?? ''));
    $reminderName = '';
    if (is_array($reminder)) {
        $reminderName = function_exists('jeab_purchase_reminder_name') ? jeab_purchase_reminder_name($reminder) : trim((string) ($reminder['customer'] ?? ''));
    }
    $nameCandidate = $storedName !== '' ? $storedName : ($lmName !== '' ? $lmName : $reminderName);
    $nameSource = $storedName !== '' ? 'order' : ($lmName !== '' ? 'lantmateriet' : ($reminderName !== '' ? 'paminnelse' : ''));
    $medBlocking = false;
    $medHas = false;
    foreach (jeab_ordrar_other_owners(jeab_ordrar_people_list($row['medsokande'] ?? [], 8), $storedName) as $med) {
        if (trim((string) ($med['name'] ?? '')) === '') {
            continue;
        }
        $medHas = true;
        if (trim((string) ($med['personnummer'] ?? '')) === '') {
            $medBlocking = true;
            break;
        }
    }
    $medPnr = ['candidate' => '', 'source' => ''];
    if ($medHas && !$medBlocking) {
        $medPnr = ['candidate' => 'finns', 'source' => 'order'];
    } elseif ($medBlocking) {
        $medPnr = jeab_ordrar_bekrafta_pnr_present(['personnummer' => '', 'owners' => []], null, $suggest);
        if ($medPnr['candidate'] !== 'finns') {
            $medPnr = ['candidate' => '', 'source' => ''];
        }
    }
    $havePrice = function_exists('jeab_ordrar_att_betala_amount') ? jeab_ordrar_att_betala_amount($row) : jeab_ordrar_positive_money($row['pris'] ?? 0);
    $price = $havePrice > 0
        ? ['amount' => $havePrice, 'source' => 'order']
        : jeab_ordrar_price_candidate($row, $reminder);
    $serverPrice = ($kind === 'batteri' || $kind === 'sol') ? 'kundpris' : 'pris';
    $blockers = [
        [
            'key' => 'produkt',
            'label' => 'produkt',
            'blocking' => $kind === '' ? 1 : 0,
            'candidate' => $productCandidate,
            'source' => $productSource,
        ],
        [
            'key' => 'personnummer',
            'label' => 'personnummer',
            'blocking' => jeab_ordrar_canonical_personnummer($row) === '' ? 1 : 0,
            'candidate' => $pnr['candidate'],
            'source' => $pnr['source'],
        ],
        [
            'key' => 'fastighetsbeteckning',
            'label' => 'fastighetsbeteckning',
            'blocking' => $storedFast === '' ? 1 : 0,
            'candidate' => $fastCandidate,
            'source' => $fastSource,
        ],
        [
            'key' => 'huvudsokande',
            'label' => 'huvudsökande',
            'blocking' => $storedName === '' ? 1 : 0,
            'candidate' => $nameCandidate,
            'source' => $nameSource,
        ],
        [
            'key' => 'medsokande_pnr',
            'label' => 'personnummer för medsökande',
            'blocking' => $medBlocking ? 1 : 0,
            'candidate' => $medPnr['candidate'],
            'source' => $medPnr['source'],
        ],
        [
            'key' => 'pris',
            'label' => 'pris',
            'server_label' => $serverPrice,
            'blocking' => $havePrice > 0 ? 0 : 1,
            'candidate' => (int) ($price['amount'] ?? 0),
            'source' => (string) ($price['source'] ?? ''),
        ],
    ];

    return jeab_fastighet_scrub_public(jeab_ordrar_scrub_pnr([
        'ok' => true,
        'action' => 'ordrar.bekrafta_blockers',
        'dry' => 1,
        'written' => 0,
        'order_id' => $id,
        'kind' => $kind,
        'blockers' => $blockers,
        'status' => 200,
    ]));
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_bekrafta_blockers_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.bekrafta_blockers', 'status' => 401];
    }

    return jeab_ordrar_bekrafta_blockers($store, $input);
}

/**
 * Vad som kan fyllas utan att röra ifyllda fält. Inget Fortnox.
 *
 * @param array<string, mixed> $row
 * @return array{diff:array<string,mixed>,suggest:array<string,mixed>,union:list<array<string,mixed>>}
 */
function jeab_ordrar_bekrafta_refetch_requested(array $input): bool
{
    if (!array_key_exists('refetch', $input)) {
        return false;
    }
    $value = $input['refetch'];

    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

function jeab_ordrar_bekrafta_fill_plan(array $row, ?array $reminder, bool $refetch = false): array
{
    $suggest = jeab_ordrar_bekrafta_lookup($row, $reminder, $refetch);
    $oldPeople = function_exists('jeab_fastighet_stored_people') ? jeab_fastighet_stored_people($row) : [];
    $newPeople = function_exists('jeab_fastighet_suggest_people') ? jeab_fastighet_suggest_people($suggest) : [];
    $union = $newPeople === [] || !function_exists('jeab_fastighet_union_people')
        ? $oldPeople
        : jeab_fastighet_union_people($oldPeople, $newPeople);
    if ($refetch && $newPeople !== []) {
        $union = $newPeople;
    }
    $diff = [];
    $oldFast = function_exists('jeab_ordrar_format_beteckning')
        ? jeab_ordrar_format_beteckning((string) ($row['fastighetsbeteckning'] ?? ''))
        : trim((string) ($row['fastighetsbeteckning'] ?? ''));
    $newFast = function_exists('jeab_ordrar_format_beteckning')
        ? jeab_ordrar_format_beteckning((string) ($suggest['fastighetsbeteckning'] ?? ''))
        : trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
    if ($newFast !== '' && ($refetch ? $oldFast !== $newFast : $oldFast === '')) {
        $diff['fastighetsbeteckning'] = ['old' => $oldFast, 'new' => $newFast, 'source' => 'lantmateriet'];
    }
    $ownerKeysDiffer = false;
    if ($refetch && $newPeople !== [] && function_exists('jeab_fastighet_person_key')) {
        $ownerKeysDiffer = array_map('jeab_fastighet_person_key', $oldPeople) !== array_map('jeab_fastighet_person_key', $newPeople);
    }
    if (($refetch && $ownerKeysDiffer) || (!$refetch && count($union) > count($oldPeople))) {
        $shown = $refetch ? $newPeople : $union;
        $diff['owners'] = [
            'old' => function_exists('jeab_fastighet_owner_public') ? array_map('jeab_fastighet_owner_public', $oldPeople) : [],
            'new' => function_exists('jeab_fastighet_owner_public') ? array_map('jeab_fastighet_owner_public', $shown) : [],
            'source' => 'lantmateriet',
        ];
    }
    $havePris = jeab_ordrar_positive_money($row['pris'] ?? 0);
    $haveKund = jeab_ordrar_positive_money($row['kundpris'] ?? 0);
    if ($havePris <= 0 && $haveKund <= 0) {
        $price = jeab_ordrar_price_candidate($row, $reminder);
        if ((int) ($price['amount'] ?? 0) > 0) {
            $diff['pris'] = ['old' => 0, 'new' => (int) $price['amount'], 'source' => (string) ($price['source'] ?? '')];
        }
    }

    return ['diff' => $diff, 'suggest' => $suggest, 'union' => $union];
}

/**
 * ordrar.bekrafta_fill. dry=1 är förhandsvisning. dry=0 fyller tomma fält och sparar ångring.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_bekrafta_fill(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = function_exists('jeab_ordrar_backfill_wants_dry') ? jeab_ordrar_backfill_wants_dry($input) : true;
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange order_id', 'action' => 'ordrar.bekrafta_fill', 'dry' => $dry ? 1 : 0, 'status' => 422];
    }
    $row = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => 'ordrar.bekrafta_fill', 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'status' => 404];
    }
    $reminder = null;
    if (function_exists('jeab_ordrar_reminder_for_row')) {
        try {
            $got = jeab_ordrar_reminder_for_row($row);
            $reminder = is_array($got) ? $got : null;
        } catch (Throwable) {
            $reminder = null;
        }
    }
    $refetch = jeab_ordrar_bekrafta_refetch_requested($input);
    $plan = jeab_ordrar_bekrafta_fill_plan($row, $reminder, $refetch);
    $diff = is_array($plan['diff'] ?? null) ? $plan['diff'] : [];
    $base = [
        'ok' => true,
        'action' => 'ordrar.bekrafta_fill',
        'dry' => $dry ? 1 : 0,
        'order_id' => $id,
        'diff' => $diff,
        'refetch' => $refetch ? 1 : 0,
        'written' => 0,
        'status' => 200,
    ];
    if ($dry || $diff === []) {
        return jeab_fastighet_scrub_public(jeab_ordrar_scrub_pnr($base));
    }
    $links = function_exists('jeab_fastighet_link_snapshot') ? jeab_fastighet_link_snapshot($row) : [];
    $slice = function_exists('jeab_fastighet_order_slice') ? jeab_fastighet_order_slice($row) : [];
    $slice['pris'] = $row['pris'] ?? null;
    $slice['kundpris'] = $row['kundpris'] ?? null;
    $batch = bin2hex(random_bytes(8));
    if (!function_exists('jeab_fastighet_undo_add') || !jeab_fastighet_undo_add($batch, $id, trim((string) ($row['contact_id'] ?? '')), $slice, null)) {
        return [
            'ok' => false,
            'error' => 'Kunde inte spara ångringen.',
            'action' => 'ordrar.bekrafta_fill',
            'order_id' => $id,
            'dry' => 0,
            'written' => 0,
            'status' => 503,
        ];
    }
    $suggest = is_array($plan['suggest'] ?? null) ? $plan['suggest'] : [];
    $union = is_array($plan['union'] ?? null) ? $plan['union'] : [];
    $applyDiff = $diff;
    if (isset($applyDiff['pris'])) {
        unset($applyDiff['pris']);
    }
    $patched = function_exists('jeab_purchase_patch')
        ? jeab_purchase_patch($id, function (array $fresh) use ($applyDiff, $suggest, $union, $links, $reminder, $diff, $refetch): array {
            $keepName = trim((string) ($fresh['huvudsokande'] ?? ''));
            $keepPnr = trim((string) ($fresh['personnummer'] ?? ''));
            $keepFast = trim((string) ($fresh['fastighetsbeteckning'] ?? ''));
            if ($applyDiff !== [] && function_exists('jeab_fastighet_apply_order_fields')) {
                $fresh = jeab_fastighet_apply_order_fields($fresh, $applyDiff, $suggest, $union, $refetch);
            }
            // Användarens omhämtning behåller Lantmäteriets nya beteckning, namn och personnummer.
            // De gamla värdena ligger redan i ångringsraden.
            if (!$refetch && $keepFast !== '') {
                $fresh['fastighetsbeteckning'] = $keepFast;
            }
            if (!$refetch && $keepName !== '') {
                $fresh['huvudsokande'] = $keepName;
            }
            if (!$refetch && $keepPnr !== '') {
                $fresh['personnummer'] = $keepPnr;
            }
            if (isset($diff['pris'])) {
                $fresh = jeab_ordrar_apply_carried_price($fresh, $reminder);
            }
            if (function_exists('jeab_fastighet_restore_links')) {
                $fresh = jeab_fastighet_restore_links($fresh, $links);
            }
            $fresh['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');

            return $fresh;
        })
        : null;
    if (!is_array($patched)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => 'ordrar.bekrafta_fill', 'order_id' => $id, 'dry' => 0, 'batch_id' => $batch, 'status' => 404];
    }
    if (function_exists('jeab_fastighet_log')) {
        jeab_fastighet_log('apply', $id, $batch, array_keys($diff));
    }
    $base['dry'] = 0;
    $base['written'] = 1;
    $base['batch_id'] = $batch;

    return jeab_fastighet_scrub_public(jeab_ordrar_scrub_pnr($base));
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_bekrafta_fill_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.bekrafta_fill', 'status' => 401];
    }

    return jeab_ordrar_bekrafta_fill($store, $input);
}

function jeab_ordrar_set_fastighet_flag(array $input, string $key): bool
{
    if (!array_key_exists($key, $input)) {
        return false;
    }
    $value = $input[$key];

    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

/**
 * Ersätter ägarlistan med Lantmäteriets personer. Gamla namn och personnummer lämnas inte kvar.
 *
 * @param array<string, mixed> $row
 * @param list<array<string, mixed>> $people
 * @return array<string, mixed>
 */
function jeab_ordrar_replace_lm_owners(array $row, array $people): array
{
    $people = function_exists('jeab_ordrar_people_list') ? jeab_ordrar_people_list($people, 8) : [];
    if ($people === []) {
        return $row;
    }
    $huvud = $people[0];
    $name = trim((string) ($huvud['name'] ?? ''));
    $pnr = function_exists('jeab_ordrar_format_pnr')
        ? jeab_ordrar_format_pnr((string) ($huvud['personnummer'] ?? ''))
        : trim((string) ($huvud['personnummer'] ?? ''));
    if (function_exists('jeab_ordrar_owners_split_huvud')) {
        [$owners, $med] = jeab_ordrar_owners_split_huvud($people, $name, $pnr);
    } else {
        $owners = $people;
        $med = [];
    }
    $row['owners'] = $owners;
    $row['medsokande'] = $med;
    if ($name !== '') {
        $row['huvudsokande'] = $name;
    }
    if ($pnr !== '') {
        $row['personnummer'] = $pnr;
    }
    $addr = trim((string) ($huvud['address'] ?? ''));
    if ($addr !== '') {
        $row['huvudsokande_address'] = $addr;
    }

    return $row;
}

/**
 * ordrar.set_fastighet. dry=1 är standard och skriver inget.
 * dry=0 kräver confirm=SAM. Sätter fastighetsbeteckning som Objekt-sparningen
 * och kan byta ägare mot Lantmäteriet för den beteckningen. Ångring sparas först.
 * Inget mejl, sms eller Fortnox. Personnummer lämnar varken svar eller logg.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_set_fastighet_run(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = function_exists('jeab_ordrar_backfill_wants_dry') ? jeab_ordrar_backfill_wants_dry($input) : true;
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    $action = 'ordrar.set_fastighet';
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange order_id', 'action' => $action, 'dry' => $dry ? 1 : 0, 'written' => 0, 'status' => 422];
    }
    if (!array_key_exists('fastighetsbeteckning', $input) || is_array($input['fastighetsbeteckning'])) {
        return ['ok' => false, 'error' => 'ange fastighetsbeteckning', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'written' => 0, 'status' => 422];
    }
    $nextFast = trim((string) $input['fastighetsbeteckning']);
    if ($nextFast === '') {
        return ['ok' => false, 'error' => 'ange fastighetsbeteckning', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'written' => 0, 'status' => 422];
    }
    $refetch = jeab_ordrar_set_fastighet_flag($input, 'refetch_owners');
    $row = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'written' => 0, 'status' => 404];
    }
    $beforeFast = trim((string) ($row['fastighetsbeteckning'] ?? ''));
    $people = [];
    $lookupEmpty = 0;
    if ($refetch) {
        $reminder = null;
        if (function_exists('jeab_ordrar_reminder_for_row')) {
            try {
                $got = jeab_ordrar_reminder_for_row($row);
                $reminder = is_array($got) ? $got : null;
            } catch (Throwable) {
                $reminder = null;
            }
        }
        $query = jeab_ordrar_lookup_query($reminder, $row);
        $wasLive = jeab_ordrar_external_live();
        jeab_ordrar_external_live(true);
        $hadBypass = array_key_exists('_JEAB_LM_CACHE_BYPASS', $GLOBALS);
        $wasBypass = $GLOBALS['_JEAB_LM_CACHE_BYPASS'] ?? null;
        $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = true;
        $GLOBALS['_JEAB_LM_FRESH'] = true;
        try {
            $found = jeab_ordrar_beteckning_lookup($nextFast, $query, '');
        } catch (Throwable $e) {
            if (function_exists('jeab_rot_lookup_log')) {
                jeab_rot_lookup_log('fel vid uppslag på beteckning', get_class($e));
            }
            $found = jeab_ordrar_lookup_empty();
        } finally {
            jeab_ordrar_external_live($wasLive);
            unset($GLOBALS['_JEAB_LM_FRESH']);
            if ($hadBypass) {
                $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = $wasBypass;
            } else {
                unset($GLOBALS['_JEAB_LM_CACHE_BYPASS']);
            }
        }
        $gotFast = trim((string) ($found['fastighetsbeteckning'] ?? ''));
        $matched = $gotFast !== '' && jeab_ordrar_beteckning_same($nextFast, $gotFast);
        $people = $matched && function_exists('jeab_ordrar_people_list') ? jeab_ordrar_people_list($found['owners'] ?? [], 8) : [];
        $lookupEmpty = $matched ? 0 : 1;
    }
    $oldPeople = function_exists('jeab_fastighet_stored_people') ? jeab_fastighet_stored_people($row) : [];
    $ownersChange = $refetch && $people !== [] && function_exists('jeab_fastighet_person_key')
        && array_map('jeab_fastighet_person_key', $oldPeople) !== array_map('jeab_fastighet_person_key', $people);
    $fastChange = jeab_ordrar_format_beteckning($beforeFast) !== jeab_ordrar_format_beteckning($nextFast);
    $changed = $fastChange || $ownersChange;
    $base = [
        'ok' => true,
        'action' => $action,
        'dry' => $dry ? 1 : 0,
        'order_id' => $id,
        'before' => $beforeFast,
        'after' => $nextFast,
        'changed' => $changed ? 1 : 0,
        'refetch_owners' => $refetch ? 1 : 0,
        'lookup_empty' => $lookupEmpty,
        'owner_count' => $refetch ? count($people) : count($oldPeople),
        'owners' => jeab_ordrar_owner_names_public($refetch ? $people : $oldPeople),
        'written' => 0,
        'status' => 200,
    ];
    if ($dry || !$changed) {
        return jeab_fastighet_scrub_public(jeab_ordrar_scrub_pnr($base));
    }
    if (trim((string) ($input['confirm'] ?? '')) !== 'SAM') {
        return [
            'ok' => false,
            'error' => 'dry=0 kräver confirm=SAM',
            'action' => $action,
            'dry' => 0,
            'order_id' => $id,
            'written' => 0,
            'status' => 422,
        ];
    }
    $slice = function_exists('jeab_fastighet_order_slice') ? jeab_fastighet_order_slice($row) : [
        'fastighetsbeteckning' => $row['fastighetsbeteckning'] ?? null,
        'owners' => $row['owners'] ?? null,
        'medsokande' => $row['medsokande'] ?? null,
        'huvudsokande' => $row['huvudsokande'] ?? null,
        'personnummer' => $row['personnummer'] ?? null,
    ];
    $batch = bin2hex(random_bytes(8));
    if (!function_exists('jeab_fastighet_undo_add') || !jeab_fastighet_undo_add($batch, $id, trim((string) ($row['contact_id'] ?? '')), $slice, null)) {
        return [
            'ok' => false,
            'error' => 'Kunde inte spara ångringen.',
            'action' => $action,
            'order_id' => $id,
            'dry' => 0,
            'written' => 0,
            'status' => 503,
        ];
    }
    $fields = [];
    if ($fastChange) {
        $fields[] = 'fastighetsbeteckning';
    }
    if ($ownersChange) {
        $fields[] = 'owners';
    }
    if (function_exists('jeab_fastighet_log')) {
        jeab_fastighet_log('backup', $id, $batch, $fields);
    }
    $before = $row;
    $patched = null;
    if ($ownersChange) {
        $work = $row;
        $work['fastighetsbeteckning'] = $nextFast;
        $work = jeab_ordrar_mark_posted_identity($before, $work, 'fastighetsbeteckning', '', jeab_ordrar_address_key($before));
        $wasLive = jeab_ordrar_external_live();
        jeab_ordrar_external_live(true);
        $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = true;
        $GLOBALS['_JEAB_LM_FRESH'] = true;
        $GLOBALS['_JEAB_LM_OWNER_REPLACE'] = true;
        try {
            $patched = jeab_ordrar_sync_lookup_owners($work, isset($reminder) && is_array($reminder) ? $reminder : null, true);
        } catch (Throwable) {
            $patched = null;
        } finally {
            jeab_ordrar_external_live($wasLive);
            unset($GLOBALS['_JEAB_LM_FRESH'], $GLOBALS['_JEAB_LM_OWNER_REPLACE'], $GLOBALS['_JEAB_LM_CACHE_BYPASS']);
        }
        $stored = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
        $storedName = is_array($stored) ? trim((string) ($stored['owners'][0]['name'] ?? '')) : '';
        $wantName = trim((string) ($people[0]['name'] ?? ''));
        if (!is_array($stored) || ($wantName !== '' && $storedName !== $wantName)) {
            $patched = null;
        } else {
            $patched = $stored;
            if (trim((string) ($patched['huvudsokande'] ?? '')) === '' && function_exists('jeab_purchase_patch')) {
                $restored = jeab_purchase_patch($id, function (array $fresh) use ($people): array {
                    return jeab_ordrar_replace_lm_owners($fresh, $people);
                });
                if (is_array($restored)) {
                    $patched = $restored;
                }
            }
        }
    }
    if (!is_array($patched)) {
        $patched = function_exists('jeab_purchase_patch')
            ? jeab_purchase_patch($id, function (array $fresh) use ($before, $nextFast, $people, $ownersChange): array {
                $fresh['fastighetsbeteckning'] = $nextFast;
                $fresh = jeab_ordrar_mark_posted_identity($before, $fresh, 'fastighetsbeteckning', '', jeab_ordrar_address_key($before));
                if ($ownersChange) {
                    $fresh = jeab_ordrar_replace_lm_owners($fresh, $people);
                }
                $fresh['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');

                return $fresh;
            })
            : null;
    }
    if (!is_array($patched)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => $action, 'order_id' => $id, 'dry' => 0, 'batch_id' => $batch, 'status' => 404];
    }
    if (function_exists('jeab_fastighet_log')) {
        jeab_fastighet_log('apply', $id, $batch, $fields);
    }
    $base['dry'] = 0;
    $base['written'] = 1;
    $base['batch_id'] = $batch;

    return jeab_fastighet_scrub_public(jeab_ordrar_scrub_pnr($base));
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_set_fastighet_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.set_fastighet', 'status' => 401];
    }

    return jeab_ordrar_set_fastighet_run($store, $input);
}

/**
 * Vilka sparade ägare som ska ligga kvar. Index eller förnamn/helt namn.
 *
 * @param list<array<string, mixed>> $people
 * @param list<mixed> $keep
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_set_owners_select(array $people, array $keep): array
{
    $chosen = [];
    $seen = [];
    foreach ($keep as $token) {
        if (is_int($token) || (is_string($token) && preg_match('/^\d+$/', $token) === 1)) {
            $index = (int) $token;
            if (!isset($people[$index]) || isset($seen[$index])) {
                continue;
            }
            $seen[$index] = true;
            $chosen[] = $people[$index];
            continue;
        }
        $needle = jeab_ordrar_name_fold(trim((string) $token));
        if ($needle === '') {
            continue;
        }
        foreach ($people as $index => $person) {
            if (isset($seen[$index]) || !is_array($person)) {
                continue;
            }
            $name = trim((string) ($person['name'] ?? ''));
            $parts = jeab_ordrar_name_parts($name);
            $first = $parts[0] ?? '';
            if ($needle !== $first && $needle !== jeab_ordrar_name_fold($name)) {
                continue;
            }
            $seen[$index] = true;
            $chosen[] = $person;
        }
    }

    return $chosen;
}

/**
 * Ägare från jeab_lm_lookup_property. Flera fastigheter: bara den som
 * matchar orderns fastighetsbeteckning. En träff används som den är.
 *
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_set_owners_people_from_lookup(array $found, string $orderFast): array
{
    $nodes = [];
    $listed = $found['properties'] ?? null;
    if (is_array($listed) && array_is_list($listed) && count($listed) > 1) {
        foreach ($listed as $item) {
            if (is_array($item)) {
                $nodes[] = $item;
            }
        }
    }
    if (count($nodes) > 1) {
        $matched = [];
        foreach ($nodes as $node) {
            $fast = trim((string) ($node['fastighetsbeteckning'] ?? ''));
            if ($fast !== '' && jeab_ordrar_beteckning_same($orderFast, $fast)) {
                $matched[] = $node;
            }
        }
        $nodes = $matched;
    } elseif ($nodes === []) {
        $nodes = [$found];
    }
    $owners = [];
    foreach ($nodes as $node) {
        foreach (is_array($node['owners'] ?? null) ? $node['owners'] : [] as $owner) {
            if (is_array($owner)) {
                $owners[] = $owner;
            }
        }
    }

    return function_exists('jeab_ordrar_people_list') ? jeab_ordrar_people_list($owners, 8) : [];
}

/**
 * Samma autocomplete som Manuell order. Bara adress och objekt-id.
 *
 * @return list<array{address:string,objektId:string}>
 */
function jeab_ordrar_lm_candidate_list(string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    if (!function_exists('jeab_rot_suggest_addresses')) {
        require_once __DIR__ . '/address_lookup.php';
    }
    try {
        $rows = jeab_rot_suggest_addresses($query);
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $address = trim((string) ($row['raw'] ?? $row['address'] ?? ''));
        $id = trim((string) ($row['id'] ?? $row['objektId'] ?? $row['objektidentitet'] ?? ''));
        if ($address === '' && $id === '') {
            continue;
        }
        $out[] = ['address' => $address, 'objektId' => $id];
    }

    return $out;
}

/**
 * Gata, husnummer och postnummer är samma. Utan postnummer i frågan blir det ingen träff.
 *
 * @param list<array{address:string,objektId:string}> $candidates
 * @return list<array{address:string,objektId:string}>
 */
function jeab_ordrar_lm_candidates_exact(string $query, array $candidates): array
{
    if (!function_exists('jeab_rot_address_identity') || !function_exists('jeab_rot_address_exact')) {
        require_once __DIR__ . '/address_lookup.php';
    }
    $want = jeab_rot_address_identity($query);
    if ($want['postal'] === '' || $want['number'] === '' || $want['words'] === []) {
        return [];
    }
    $hit = [];
    foreach ($candidates as $row) {
        $address = trim((string) ($row['address'] ?? ''));
        if ($address !== '' && jeab_rot_address_exact($query, $address)) {
            $hit[] = $row;
        }
    }

    return $hit;
}

/**
 * ordrar.lm_candidates. Alltid torr. Autocomplete-rader med adress och objekt-id.
 * Inget personnummer och ingen skrivning.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_lm_candidates_run(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $query = trim((string) ($input['query'] ?? $input['address'] ?? ''));
    $action = 'ordrar.lm_candidates';
    if ($query === '') {
        return ['ok' => false, 'error' => 'ange query', 'action' => $action, 'dry' => 1, 'written' => 0, 'status' => 422];
    }
    $candidates = jeab_ordrar_lm_candidate_list($query);

    return jeab_fastighet_scrub_public(jeab_ordrar_scrub_pnr([
        'ok' => true,
        'action' => $action,
        'dry' => 1,
        'written' => 0,
        'query' => $query,
        'candidates' => $candidates,
        'status' => 200,
    ]));
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_lm_candidates_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.lm_candidates', 'status' => 401];
    }

    return jeab_ordrar_lm_candidates_run($store, $input);
}

/**
 * ordrar.set_owners. Utan adress: behåll valda sparade ägare.
 * Med query: samma autocomplete som Manuell order, en exakt gata+nummer+postnummer,
 * sedan jeab_rot_lookup_address(radens adress, radens objekt-id).
 * objektId eller objektidentitet hoppar över autocomplete.
 * dry=1 är standard. dry=0 kräver confirm=SAM. Ångring sparas först.
 * Svaret har namn och andel, aldrig personnummer.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_set_owners_run(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = function_exists('jeab_ordrar_backfill_wants_dry') ? jeab_ordrar_backfill_wants_dry($input) : true;
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    $action = 'ordrar.set_owners';
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange order_id', 'action' => $action, 'dry' => $dry ? 1 : 0, 'written' => 0, 'status' => 422];
    }
    $address = trim((string) ($input['address'] ?? ''));
    $query = trim((string) ($input['query'] ?? ''));
    $objektId = trim((string) ($input['objektId'] ?? $input['objektidentitet'] ?? ''));
    $fromLookup = $query !== '' || $address !== '' || $objektId !== '';
    if (!$fromLookup && (!isset($input['keep']) || !is_array($input['keep']) || $input['keep'] === [])) {
        return ['ok' => false, 'error' => 'ange keep', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'written' => 0, 'status' => 422];
    }
    $row = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'written' => 0, 'status' => 404];
    }
    $oldPeople = function_exists('jeab_fastighet_stored_people') ? jeab_fastighet_stored_people($row) : [];
    if ($fromLookup) {
        $orderFast = trim((string) ($row['fastighetsbeteckning'] ?? ''));
        if (!function_exists('jeab_rot_lookup_address') || !function_exists('jeab_rot_suggest_addresses')) {
            require_once __DIR__ . '/address_lookup.php';
        }
        if ($objektId !== '') {
            $lookupAddress = $address !== '' ? $address : $query;
            if ($lookupAddress === '') {
                return ['ok' => false, 'error' => 'ange address', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'written' => 0, 'status' => 422];
            }
            try {
                $found = jeab_rot_lookup_address($lookupAddress, $objektId);
            } catch (Throwable) {
                $found = [];
            }
        } else {
            $lookupQuery = $query !== '' ? $query : $address;
            $candidates = jeab_ordrar_lm_candidate_list($lookupQuery);
            $exact = jeab_ordrar_lm_candidates_exact($lookupQuery, $candidates);
            $chosen = count($exact) === 1 ? $exact[0] : null;
            $chosenId = is_array($chosen) ? trim((string) ($chosen['objektId'] ?? '')) : '';
            $chosenAddress = is_array($chosen) ? trim((string) ($chosen['address'] ?? '')) : '';
            if ($chosenId === '' || $chosenAddress === '') {
                return jeab_fastighet_scrub_public(jeab_ordrar_scrub_pnr([
                    'ok' => false,
                    'error' => count($exact) > 1 ? 'flera adresser' : 'ingen exakt adress',
                    'action' => $action,
                    'order_id' => $id,
                    'dry' => $dry ? 1 : 0,
                    'written' => 0,
                    'candidates' => $candidates,
                    'status' => 409,
                ]));
            }
            try {
                $found = jeab_rot_lookup_address($chosenAddress, $chosenId);
            } catch (Throwable) {
                $found = [];
            }
        }
        $people = jeab_ordrar_set_owners_people_from_lookup(
            is_array($found) ? $found : [],
            $orderFast
        );
        if ($people === []) {
            return ['ok' => false, 'error' => 'ingen ägare från adressen', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'written' => 0, 'status' => 422];
        }
    } else {
        $people = jeab_ordrar_set_owners_select($oldPeople, array_values($input['keep']));
        if ($people === []) {
            return ['ok' => false, 'error' => 'ingen ägare träffade keep', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'written' => 0, 'status' => 422];
        }
    }
    $changed = !function_exists('jeab_fastighet_person_key')
        || array_map('jeab_fastighet_person_key', $oldPeople) !== array_map('jeab_fastighet_person_key', $people);
    $base = [
        'ok' => true,
        'action' => $action,
        'dry' => $dry ? 1 : 0,
        'order_id' => $id,
        'changed' => $changed ? 1 : 0,
        'owner_count' => count($people),
        'owners' => jeab_ordrar_owner_names_public($people),
        'written' => 0,
        'status' => 200,
    ];
    if ($dry || !$changed) {
        return jeab_fastighet_scrub_public(jeab_ordrar_scrub_pnr($base));
    }
    if (trim((string) ($input['confirm'] ?? '')) !== 'SAM') {
        return [
            'ok' => false,
            'error' => 'dry=0 kräver confirm=SAM',
            'action' => $action,
            'dry' => 0,
            'order_id' => $id,
            'written' => 0,
            'status' => 422,
        ];
    }
    $slice = function_exists('jeab_fastighet_order_slice') ? jeab_fastighet_order_slice($row) : [
        'fastighetsbeteckning' => $row['fastighetsbeteckning'] ?? null,
        'owners' => $row['owners'] ?? null,
        'medsokande' => $row['medsokande'] ?? null,
        'huvudsokande' => $row['huvudsokande'] ?? null,
        'personnummer' => $row['personnummer'] ?? null,
    ];
    $batch = bin2hex(random_bytes(8));
    if (!function_exists('jeab_fastighet_undo_add') || !jeab_fastighet_undo_add($batch, $id, trim((string) ($row['contact_id'] ?? '')), $slice, null)) {
        return [
            'ok' => false,
            'error' => 'Kunde inte spara ångringen.',
            'action' => $action,
            'order_id' => $id,
            'dry' => 0,
            'written' => 0,
            'status' => 503,
        ];
    }
    if (function_exists('jeab_fastighet_log')) {
        jeab_fastighet_log('backup', $id, $batch, ['owners']);
    }
    $patched = function_exists('jeab_purchase_patch')
        ? jeab_purchase_patch($id, function (array $fresh) use ($people): array {
            $fresh = jeab_ordrar_replace_lm_owners($fresh, $people);
            $fresh['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');

            return $fresh;
        })
        : null;
    if (!is_array($patched)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => $action, 'order_id' => $id, 'dry' => 0, 'batch_id' => $batch, 'status' => 404];
    }
    if (function_exists('jeab_fastighet_log')) {
        jeab_fastighet_log('apply', $id, $batch, ['owners']);
    }
    $synced = jeab_ordrar_sync_owner_pnr_commit($patched);
    $base['dry'] = 0;
    $base['written'] = 1;
    $base['batch_id'] = $batch;
    $base['contact_synced'] = (int) ($synced['written'] ?? 0) === 1 ? 1 : 0;
    if ((int) ($synced['written'] ?? 0) === 1 && trim((string) ($synced['batch_id'] ?? '')) !== '') {
        $base['contact_batch_id'] = (string) $synced['batch_id'];
    }

    return jeab_fastighet_scrub_public(jeab_ordrar_scrub_pnr($base));
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_set_owners_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.set_owners', 'status' => 401];
    }

    return jeab_ordrar_set_owners_run($store, $input);
}

/**
 * ordrar.fastighet_probe. Alltid torr. Varje sökning och vad som kom tillbaka.
 * Inget personnummer, ingen skrivning.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_fastighet_probe(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange order_id', 'action' => 'ordrar.fastighet_probe', 'dry' => 1, 'written' => 0, 'status' => 422];
    }
    $row = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => 'ordrar.fastighet_probe', 'order_id' => $id, 'dry' => 1, 'written' => 0, 'status' => 404];
    }
    $reminder = null;
    if (function_exists('jeab_ordrar_reminder_for_row')) {
        try {
            $got = jeab_ordrar_reminder_for_row($row);
            $reminder = is_array($got) ? $got : null;
        } catch (Throwable) {
            $reminder = null;
        }
    }
    $query = function_exists('jeab_ordrar_lookup_query') ? jeab_ordrar_lookup_query($reminder, $row) : '';
    $wasLive = jeab_ordrar_external_live();
    $hadBypass = array_key_exists('_JEAB_LM_CACHE_BYPASS', $GLOBALS);
    $wasBypass = $GLOBALS['_JEAB_LM_CACHE_BYPASS'] ?? null;
    jeab_ordrar_external_live(true);
    $GLOBALS['_JEAB_BEKRAFTA_LOOKUP'] = true;
    $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = true;
    $GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] = [];
    try {
        jeab_ordrar_confirm_suggest($row, $reminder);
    } catch (Throwable) {
        $GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] = is_array($GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] ?? null) ? $GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] : [];
    } finally {
        jeab_ordrar_external_live($wasLive);
        unset($GLOBALS['_JEAB_BEKRAFTA_LOOKUP']);
        if ($hadBypass) {
            $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = $wasBypass;
        } else {
            unset($GLOBALS['_JEAB_LM_CACHE_BYPASS']);
        }
    }
    $tries = [];
    foreach (is_array($GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] ?? null) ? $GLOBALS['_JEAB_ROT_LOOKUP_TRACE'] : [] as $step) {
        if (!is_array($step)) {
            continue;
        }
        $addresses = [];
        foreach (is_array($step['choice_addresses'] ?? null) ? $step['choice_addresses'] : [] as $label) {
            $label = trim((string) $label);
            if ($label !== '') {
                $addresses[] = $label;
            }
        }
        $tries[] = [
            'query' => trim((string) ($step['query'] ?? '')),
            'accepted' => !empty($step['accepted']) ? 1 : 0,
            'fastighet' => !empty($step['fastighet']) ? 1 : 0,
            'beteckning' => trim((string) ($step['beteckning'] ?? '')),
            'owners' => (int) ($step['owners'] ?? 0),
            'rows' => (int) ($step['rows'] ?? 0),
            'fetched' => trim((string) ($step['fetched'] ?? '')),
            'choices' => (int) ($step['choices'] ?? 0),
            'choice_addresses' => $addresses,
            'pnr' => !empty($step['pnr']) ? 1 : 0,
        ];
    }
    $hit = 0;
    $owners = 0;
    foreach ($tries as $step) {
        if (!empty($step['fastighet'])) {
            $hit = 1;
        }
        $owners = max($owners, (int) ($step['owners'] ?? 0));
    }

    return jeab_fastighet_scrub_public(jeab_ordrar_scrub_pnr([
        'ok' => true,
        'action' => 'ordrar.fastighet_probe',
        'dry' => 1,
        'written' => 0,
        'order_id' => $id,
        'address' => $query,
        'queries' => $tries,
        'fastighet' => $hit,
        'owners' => $owners,
        'status' => 200,
    ]));
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_fastighet_probe_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.fastighet_probe', 'status' => 401];
    }

    return jeab_ordrar_fastighet_probe($store, $input);
}

/**
 * N-53: adress och fastighet från länkad kontakt, för att fylla orörda fält
 * efter autosave. Tomt när Kontakter är av eller kontakten saknas.
 *
 * @param array<string, mixed> $row
 * @return array{gata:string,postnummer:string,ort:string,fastighetsbeteckning:string}
 */
function jeab_ordrar_objekt_contact_fill(array $row): array
{
    $out = ['gata' => '', 'postnummer' => '', 'ort' => '', 'fastighetsbeteckning' => ''];
    if (!function_exists('jeab_contacts_sole_view_for')) {
        return $out;
    }
    $view = jeab_contacts_sole_view_for($row);
    if (!is_array($view) || empty($view['linked'])) {
        return $out;
    }
    $out['gata'] = (string) ($view['street'] ?? '');
    $out['postnummer'] = (string) ($view['postal'] ?? '');
    $out['ort'] = (string) ($view['city'] ?? '');
    if (function_exists('jeab_contacts_sole_preferred_fastighet')) {
        $out['fastighetsbeteckning'] = jeab_contacts_sole_preferred_fastighet($row);
    }
    return $out;
}

require_once __DIR__ . '/ordrar_restore.php';

require_once __DIR__ . '/ordrar_delete_undo.php';
