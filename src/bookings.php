<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';
function jeab_find_or_create_customer(JeabStore $store, string $name): array
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Vem är bokningen åt?');
    }
    $row = $store->findCustomerByName($name);
    if ($row) {
        return $row;
    }
    return $store->insertCustomer($name, jeab_now());
}

function jeab_customers(JeabStore $store): array
{
    return $store->listCustomers();
}

function jeab_recent_assignees(JeabStore $store, int $limit = 8): array
{
    return $store->recentAssignees(max(1, min(20, $limit)));
}

function jeab_booking_cache_forget(?int $id = null): void
{
    // Stegen läser om bokningen är avbokad. Skrivning av bokningen tömmer den cachen.
    if (function_exists('jeab_ordrar_v3_steps_forget')) {
        jeab_ordrar_v3_steps_forget();
    }
    if (!isset($GLOBALS['_JEAB_BOOKING_CACHE']) || !is_array($GLOBALS['_JEAB_BOOKING_CACHE'])) {
        $GLOBALS['_JEAB_BOOKING_CACHE'] = [];
        return;
    }
    if ($id === null || $id <= 0) {
        $GLOBALS['_JEAB_BOOKING_CACHE'] = [];
        return;
    }
    unset($GLOBALS['_JEAB_BOOKING_CACHE'][$id]);
}

function jeab_get_booking(JeabStore $store, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    if (isset($GLOBALS['_JEAB_BOOKING_CACHE']) && is_array($GLOBALS['_JEAB_BOOKING_CACHE']) && array_key_exists($id, $GLOBALS['_JEAB_BOOKING_CACHE'])) {
        $hit = $GLOBALS['_JEAB_BOOKING_CACHE'][$id];
        if (!is_array($hit)) {
            return null;
        }
        if (function_exists('jeab_company_allowed') && !jeab_company_allowed(jeab_booking_company($hit))) {
            return null;
        }
        return $hit;
    }
    $row = $store->getBooking($id);
    if (!isset($GLOBALS['_JEAB_BOOKING_CACHE']) || !is_array($GLOBALS['_JEAB_BOOKING_CACHE'])) {
        $GLOBALS['_JEAB_BOOKING_CACHE'] = [];
    }
    $GLOBALS['_JEAB_BOOKING_CACHE'][$id] = $row;
    if (is_array($row) && function_exists('jeab_company_allowed') && !jeab_company_allowed(jeab_booking_company($row))) {
        return null;
    }
    return $row;
}

/** En fråga för listans bokningar, i stället för en SELECT per rad. */
function jeab_bookings_warm(JeabStore $store, array $ids): void
{
    $want = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $want[$id] = $id;
        }
    }
    if ($want === [] || !$store instanceof JeabPdoStore) {
        return;
    }
    if (!isset($GLOBALS['_JEAB_BOOKING_CACHE']) || !is_array($GLOBALS['_JEAB_BOOKING_CACHE'])) {
        $GLOBALS['_JEAB_BOOKING_CACHE'] = [];
    }
    $missing = [];
    foreach ($want as $id) {
        if (!array_key_exists($id, $GLOBALS['_JEAB_BOOKING_CACHE'])) {
            $missing[] = $id;
        }
    }
    if ($missing === []) {
        return;
    }
    $place = implode(',', array_fill(0, count($missing), '?'));
    $stmt = $store->pdo()->prepare(
        'SELECT id, customer_id, ' . jeab_copy_select($store->pdo(), 'bookings', jeab_copy_columns('bookings')) . ', notes, assignee, assignees, company, job_type, starts_at, ends_at, status, contact_id, created_at, updated_at FROM bookings WHERE id IN (' . $place . ')'
    );
    $stmt->execute($missing);
    $found = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $booking = jeab_hydrate_booking($row);
        $found[(int) $booking['id']] = $booking;
        $GLOBALS['_JEAB_BOOKING_CACHE'][(int) $booking['id']] = $booking;
    }
    foreach ($missing as $id) {
        if (!isset($found[$id])) {
            $GLOBALS['_JEAB_BOOKING_CACHE'][$id] = null;
        }
    }
}

/**
 * Samma rader som getBooking, i en IN-fråga (eller ett svep när lagret inte är SQL).
 *
 * @param list<int> $ids
 * @return array<int, array<string, mixed>>
 */
function jeab_bookings_by_ids(JeabStore $store, array $ids): array
{
    $want = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $want[$id] = $id;
        }
    }
    if ($want === []) {
        return [];
    }
    if ($store instanceof JeabPdoStore) {
        $pdo = $store->pdo();
        $out = [];
        foreach (array_chunk(array_values($want), 400) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id IN (' . $marks . ')');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $booking = jeab_hydrate_booking($row);
                $out[(int) $booking['id']] = $booking;
            }
        }
        return $out;
    }
    $out = [];
    foreach ($store->listAllBookings() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && isset($want[$id])) {
            $out[$id] = $row;
        }
    }
    return $out;
}

function jeab_bookings_between(JeabStore $store, string $from, string $to): array
{
    return $store->bookingsBetween($from, $to);
}

function jeab_booked_bookings(JeabStore $store): array
{
    return $store->bookingsBetween('1970-01-01 00:00:00', '2100-01-01 00:00:00');
}

function jeab_calendar_sync_outlook_log(Throwable $e): void
{
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    $why = function_exists('jeab_throwable_log_message') ? jeab_throwable_log_message($e) : $e->getMessage();
    jeab_log_app_error('calendar', 'Outlook-synk: ' . $why);
}

function jeab_booking_stamp_test(array $row): void
{
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_store')) {
        return;
    }
    try {
        $store = jeab_store();
        $fresh = $store->getBooking($id);
        if (!is_array($fresh)) {
            return;
        }
        $current = (string) ($fresh['notes'] ?? '');
        if ($current === 'TEST' || str_starts_with($current, "TEST\n")) {
            return;
        }
        $store->saveBooking($id, [
            'notes' => $current === '' ? 'TEST' : "TEST\n" . $current,
            'updated_at' => (string) ($fresh['updated_at'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('booking stamp test ' . $e::class);
    }
}

function jeab_calendar_sync_outlook(JeabStore $store, array $row): void
{
    if (function_exists('jeab_calendar_test_lead') && jeab_calendar_test_lead($row)) {
        jeab_booking_stamp_test($row);
        return;
    }
    unset($store);
    if (!empty($GLOBALS['_JEAB_QUIET_BOOKING'])) {
        return; // självtest av ombokning: testbokningen pushas inte till Outlook
    }
    unset($GLOBALS['_JEAB_GP_SYNC_USER_ERROR']);
    if (function_exists('jeab_gp_sync_booking')) {
        jeab_gp_sync_booking($row);
    }
    if (function_exists('jeab_gp_week_events_cache_forget')) {
        jeab_gp_week_events_cache_forget();
    }
    if (!empty($GLOBALS['_JEAB_GP_SYNC_USER_ERROR'])) {
        $GLOBALS['_JEAB_BOOKING_OUTLOOK_NOTE'] = 'Outlook-synken misslyckades.';
    }
}

function jeab_booking_view(): string
{
    $view = strtolower(trim((string) ($_GET['view'] ?? '')));
    return in_array($view, ['month', 'day'], true) ? $view : 'week';
}

/**
 * Dagen som dagvyn visar: ?d=ÅÅÅÅ-MM-DD, annars idag. Lör/sön blir nästa måndag
 * (kalendern visar bara mån–fre).
 */
function jeab_calendar_focus_day(): DateTimeImmutable
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $day = new DateTimeImmutable('today', $tz);
    $raw = trim((string) ($_GET['d'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);
        if ($parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $raw) {
            $day = $parsed;
        }
    }
    return jeab_calendar_weekday_step($day, 0);
}

/** Närmaste vardag: steg 0 = samma dag eller nästa vardag, ±1 = föregående/nästa vardag. */
function jeab_calendar_weekday_step(DateTimeImmutable $day, int $step): DateTimeImmutable
{
    $day = $day->setTime(0, 0);
    if ($step === 0) {
        while ((int) $day->format('N') >= 6) {
            $day = $day->modify('+1 day');
        }
        return $day;
    }
    $dir = $step > 0 ? '+1 day' : '-1 day';
    do {
        $day = $day->modify($dir);
    } while ((int) $day->format('N') >= 6);
    return $day;
}

/**
 * Kalenderns filter. Query vinner; utan who/job visas den inloggades egna bokningar
 * (samma identitet som Ordrar och Påminnelser, #288/#290–#292). Alla går att välja.
 *
 * @return array{who:?string,job:?string,explicit:bool}
 */
function jeab_booking_filter_state(): array
{
    $fp = function_exists('jeab_auth_user_fingerprint') ? (string) jeab_auth_user_fingerprint() : '';
    $key = $fp . "\0" . (string) ($_GET['who'] ?? '') . "\0" . (string) ($_GET['job'] ?? '') . "\0"
        . (string) ($_GET['co'] ?? '') . "\0" . (string) ($_GET['ws'] ?? '') . "\0" . (string) ($_GET['p'] ?? '');
    $memo = $GLOBALS['_JEAB_BOOKING_FILTER'] ?? null;
    if (is_array($memo) && isset($memo[$key]) && is_array($memo[$key])) {
        return $memo[$key];
    }
    $state = jeab_filter_from_request();
    if (!$state['explicit'] && $state['who'] === null && $state['job'] === null && function_exists('jeab_filter_login_preset')) {
        $preset = jeab_filter_login_preset(null, true);
        $state['who'] = $preset['who'];
        $state['job'] = $preset['job'];
    }
    if (!isset($GLOBALS['_JEAB_BOOKING_FILTER']) || !is_array($GLOBALS['_JEAB_BOOKING_FILTER'])) {
        $GLOBALS['_JEAB_BOOKING_FILTER'] = [];
    }
    $GLOBALS['_JEAB_BOOKING_FILTER'][$key] = $state;

    return $state;
}

function jeab_booking_who_filter(): ?string
{
    if (function_exists('jeab_filter_from_request')) {
        return jeab_booking_filter_state()['who'];
    }
    $who = trim((string) ($_GET['who'] ?? ''));
    return jeab_resolve_person_name($who, jeab_selected_company());
}

function jeab_booking_job_filter(): ?string
{
    if (function_exists('jeab_filter_from_request')) {
        return jeab_booking_filter_state()['job'];
    }
    return jeab_normalize_job_type(isset($_GET['job']) ? (string) $_GET['job'] : null);
}

function jeab_calendar_include_outlook(?string $jobFilter): bool
{
    return $jobFilter === null;
}

function jeab_calendar_compact_text(string $value): string
{
    $value = str_replace("\u{00a0}", ' ', $value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = strtr($value, [
        'å' => 'a',
        'ä' => 'a',
        'ö' => 'o',
        'é' => 'e',
        'ü' => 'u',
    ]);
    return preg_replace('/[^a-z0-9]+/u', '', $value) ?? $value;
}

function jeab_calendar_intervals_overlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
{
    try {
        $as = new DateTimeImmutable($aStart);
        $ae = new DateTimeImmutable($aEnd);
        $bs = new DateTimeImmutable($bStart);
        $be = new DateTimeImmutable($bEnd);
    } catch (Exception) {
        return false;
    }
    return $as < $be && $bs < $ae;
}

function jeab_calendar_outlook_who_on_booking(array $event, array $row): bool
{
    $who = trim((string) ($event['who'] ?? ''));
    if ($who === '') {
        return false;
    }
    $company = function_exists('jeab_booking_company') ? jeab_booking_company($row) : null;
    $resolved = jeab_resolve_person_name($who, $company);
    if ($resolved === null) {
        $resolved = jeab_resolve_person_name($who);
    }
    $want = $resolved ?? $who;
    foreach (jeab_assignees_from_row($row) as $name) {
        if (strcasecmp((string) $name, $want) === 0 || strcasecmp((string) $name, $who) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * strong: ämnet är bokningen (synkad titel, gata, ort, kund, anteckning).
 * weak: bara jobbets namn som eget ord. Det kräver samma person.
 */
function jeab_calendar_outlook_text_kind(array $event, array $row): string
{
    $titleKey = jeab_calendar_compact_text((string) ($event['title'] ?? ''));
    if ($titleKey === '') {
        return '';
    }
    // Kontakten kan bära orten som Outlook-ämnet visar, medan raden i lagret
    // bara har gatan. Båda texterna får räknas.
    if (function_exists('jeab_contacts_sole_paint_booking')) {
        $painted = jeab_contacts_sole_paint_booking($row);
        foreach (['address', 'customer_name'] as $key) {
            $raw = trim((string) ($row[$key] ?? ''));
            $alt = trim((string) ($painted[$key] ?? ''));
            if ($alt !== '' && $alt !== $raw) {
                $row[$key] = trim($raw . ' ' . $alt);
            }
        }
    }
    $pushed = '';
    if (function_exists('jeab_gp_event_title')) {
        $pushed = jeab_calendar_compact_text(jeab_gp_event_title($row));
    }
    if ($pushed !== '' && ($titleKey === $pushed || (strlen($pushed) >= 10 && str_contains($titleKey, $pushed)))) {
        return 'strong';
    }
    $address = jeab_calendar_compact_text((string) ($row['address'] ?? ''));
    if (strlen($address) >= 8 && str_contains($titleKey, $address)) {
        return 'strong';
    }
    $street = jeab_calendar_compact_text(jeab_booking_street($row['address'] ?? null));
    if ($street !== '' && strlen($street) >= 8 && str_contains($titleKey, $street)) {
        return 'strong';
    }
    $city = jeab_calendar_compact_text(jeab_booking_city($row['address'] ?? null));
    if (($street !== '' && strlen($street) >= 4 && $titleKey === $street)
        || ($city !== '' && strlen($city) >= 4 && $titleKey === $city)) {
        return 'strong';
    }
    $customer = jeab_calendar_compact_text((string) ($row['customer_name'] ?? ''));
    if ((strlen($customer) >= 8 && str_contains($titleKey, $customer))
        || ($customer !== '' && strlen($customer) >= 4 && $titleKey === $customer)) {
        return 'strong';
    }
    $notes = jeab_calendar_compact_text((string) ($row['notes'] ?? ''));
    if (strlen($titleKey) >= 8 && $notes !== '' && (str_contains($notes, $titleKey) || (strlen($notes) >= 8 && str_contains($titleKey, $notes)))) {
        return 'strong';
    }
    // Kopian saknar kundnamnet. «Ps 10» står i anteckningen. «Ivan Maxborn»
    // kan ligga i anteckning eller adress utan att vara customer_name.
    $title = (string) ($event['title'] ?? '');
    $notesRaw = (string) ($row['notes'] ?? '');
    // Bara «Batteri» är jobbnamnet, även om ordet står i anteckningen.
    $titleIsJob = false;
    foreach (jeab_job_types_from_row($row) as $job) {
        $label = JEAB_JOB_TYPES[$job] ?? '';
        if ($label !== '' && strcasecmp(trim($title), $label) === 0) {
            $titleIsJob = true;
            break;
        }
    }
    if (!$titleIsJob && jeab_calendar_outlook_phrase_in($title, $notesRaw)) {
        return 'strong';
    }
    $addressRaw = (string) ($row['address'] ?? '');
    if (strlen($titleKey) >= 8 && jeab_calendar_outlook_phrase_in($title, $addressRaw)) {
        return 'strong';
    }
    if ($customer !== '' && strlen($titleKey) >= 8 && str_contains($customer, $titleKey)) {
        return 'strong';
    }
    // «Batteri, Ljusterö»: jobbets namn och orten, utan kund. Det är bokningen
    // även när händelsen ligger i en kollegas kalender.
    $cityRaw = jeab_booking_city($row['address'] ?? null);
    $cityHit = $cityRaw !== '' && jeab_calendar_outlook_phrase_in($cityRaw, $title);
    $jobHit = false;
    foreach (jeab_job_types_from_row($row) as $job) {
        $label = JEAB_JOB_TYPES[$job] ?? '';
        if ($label !== '' && jeab_calendar_outlook_phrase_in($label, $title)) {
            $jobHit = true;
            break;
        }
    }
    if ($jobHit && !$cityHit) {
        $placeBlob = $addressRaw . ' ' . $cityRaw;
        if (preg_match_all('/\p{L}[\p{L}-]{3,}/u', $placeBlob, $placeWords)) {
            foreach ($placeWords[0] as $word) {
                if (jeab_calendar_outlook_phrase_in((string) $word, $title)) {
                    return 'strong';
                }
            }
        }
    }
    if ($cityHit && $jobHit) {
        return 'strong';
    }
    if ($jobHit) {
        return 'weak';
    }
    return '';
}

function jeab_calendar_outlook_text_matches_booking(array $event, array $row): bool
{
    return jeab_calendar_outlook_text_kind($event, $row) !== '';
}

/**
 * Ett ämnesord som eget ord. «Ps 10» i en anteckning räknas.
 * «Sol» inuti «Solkund» gör det inte.
 */
function jeab_calendar_outlook_phrase_in(string $needle, string $haystack): bool
{
    $needle = trim((string) (preg_replace('/\s+/u', ' ', $needle) ?? $needle));
    $haystack = trim($haystack);
    if ($needle === '' || $haystack === '') {
        return false;
    }
    $len = function_exists('mb_strlen') ? mb_strlen($needle, 'UTF-8') : strlen($needle);
    if ($len < 3 || preg_match('/\p{L}/u', $needle) !== 1) {
        return false;
    }
    $bits = preg_split('/\s+/u', $needle) ?: [];
    $quoted = [];
    foreach ($bits as $bit) {
        if ($bit !== '') {
            $quoted[] = preg_quote($bit, '/');
        }
    }
    if ($quoted === []) {
        return false;
    }
    $pattern = '/(?<![\p{L}\p{N}])' . implode('\s+', $quoted) . '(?![\p{L}\p{N}])/ui';

    return preg_match($pattern, $haystack) === 1;
}

/**
 * Outlook-sync som är samma jobb som en JEAB-bokning ska inte ritas extra.
 * Gata, ort, kund, anteckning eller jobb plus ort räcker även när
 * kalenderns ägare inte är tilldelad. Bara jobbnamnet kräver samma person,
 * så ett privat «Sol»-möte blir kvar.
 */
function jeab_calendar_outlook_is_duplicate(array $event, array $row): bool
{
    if (!jeab_calendar_intervals_overlap(
        (string) ($event['starts_at'] ?? ''),
        (string) ($event['ends_at'] ?? ''),
        (string) ($row['starts_at'] ?? ''),
        (string) ($row['ends_at'] ?? '')
    )) {
        return false;
    }
    $kind = jeab_calendar_outlook_text_kind($event, $row);
    if ($kind === 'strong') {
        return true;
    }
    if ($kind !== 'weak') {
        return false;
    }
    return jeab_calendar_outlook_who_on_booking($event, $row);
}

function jeab_calendar_debug_redact(string $value): string
{
    $value = preg_replace('/\b(?:19|20)?\d{6}[-+]?\d{4}\b/u', '[pnr]', $value) ?? $value;
    $value = preg_replace('/\b\d{10,12}\b/u', '[pnr]', $value) ?? $value;

    return $value;
}

/**
 * Varför en lagrad rad inte blir klickbar kopia. Samma villkor som
 * jeab_calendar_outlook_is_duplicate, plus status och kontor som vågen
 * filtrerar bort innan jämförelsen.
 *
 * @return array{match:bool,why:list<string>,overlap:bool,text:string,who_on_booking:bool,in_wave_pool:bool}
 */
function jeab_calendar_match_reject(array $event, array $row, bool $inWavePool): array
{
    $why = [];
    $status = (string) ($row['status'] ?? '');
    if ($status !== 'booked') {
        $why[] = 'status';
    }
    if (jeab_booking_company($row) !== jeab_selected_company()) {
        $why[] = 'office';
    }
    if (!$inWavePool && $status === 'booked' && jeab_booking_company($row) === jeab_selected_company()) {
        $why[] = 'window';
    }
    $overlap = jeab_calendar_intervals_overlap(
        (string) ($event['starts_at'] ?? ''),
        (string) ($event['ends_at'] ?? ''),
        (string) ($row['starts_at'] ?? ''),
        (string) ($row['ends_at'] ?? '')
    );
    if (!$overlap) {
        $why[] = 'time';
    }
    $kind = jeab_calendar_outlook_text_kind($event, $row);
    $whoOn = jeab_calendar_outlook_who_on_booking($event, $row);
    if ($kind === '') {
        $why[] = 'text';
    } elseif ($kind === 'weak' && !$whoOn) {
        $why[] = 'who';
    }
    $match = $status === 'booked'
        && jeab_booking_company($row) === jeab_selected_company()
        && $inWavePool
        && jeab_calendar_outlook_is_duplicate($event, $row);

    return [
        'match' => $match,
        'why' => $match ? [] : $why,
        'overlap' => $overlap,
        'text' => $kind === '' ? 'none' : $kind,
        'who_on_booking' => $whoOn,
        'in_wave_pool' => $inWavePool,
    ];
}

/**
 * Kundnamn i ämnet. «huvuds väg» och «Huvudsväg» blir samma compact-text.
 */
function jeab_calendar_title_has_name(string $title, string $customer): bool
{
    $name = jeab_calendar_compact_text($customer);
    if (strlen($name) < 8) {
        return false;
    }
    $titleKey = jeab_calendar_compact_text($title);

    return $titleKey !== '' && str_contains($titleKey, $name);
}

/** Gata plus husnummer. Ort ensam räcker inte. */
function jeab_calendar_title_has_street(string $title, ?string $address): bool
{
    $street = jeab_calendar_compact_text(jeab_booking_street($address));
    if ($street === '' || strlen($street) < 4 || preg_match('/\d/', $street) !== 1 || preg_match('/[a-z]/', $street) !== 1) {
        return false;
    }
    $titleKey = jeab_calendar_compact_text($title);

    return $titleKey !== '' && str_contains($titleKey, $street);
}

/**
 * @return array{name:bool,street:bool}
 */
function jeab_calendar_outlook_signals(string $title, array $row): array
{
    $painted = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($row) : $row;
    $name = jeab_calendar_title_has_name($title, (string) ($row['customer_name'] ?? ''))
        || jeab_calendar_title_has_name($title, (string) ($painted['customer_name'] ?? ''));
    $street = jeab_calendar_title_has_street($title, isset($row['address']) ? (string) $row['address'] : null)
        || jeab_calendar_title_has_street($title, isset($painted['address']) ? (string) $painted['address'] : null);

    return ['name' => $name, 'street' => $street];
}

/**
 * @return array{from:DateTimeImmutable,to:DateTimeImmutable}
 */
function jeab_calendar_outlook_span(array $event): array
{
    $tz = new DateTimeZone(date_default_timezone_get());
    try {
        $start = new DateTimeImmutable((string) ($event['starts_at'] ?? ''), $tz);
    } catch (Exception) {
        $start = new DateTimeImmutable('today', $tz);
    }
    $day = $start->setTime(0, 0);

    return ['from' => $day->modify('-7 days'), 'to' => $day->modify('+8 days')];
}

function jeab_calendar_row_in_span(array $row, DateTimeImmutable $from, DateTimeImmutable $to): bool
{
    $start = trim((string) ($row['starts_at'] ?? ''));

    return $start !== '' && $start >= $from->format('Y-m-d H:i:s') && $start < $to->format('Y-m-d H:i:s');
}

/**
 * @param list<int> $eventIds
 * @return array<string, list<array<string,mixed>>>
 */
function jeab_calendar_graph_bookings(JeabStore $store, array $eventIds): array
{
    $want = [];
    foreach ($eventIds as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $want[$id] = true;
        }
    }
    if ($want === [] || !function_exists('jeab_outlook_booking_ids_for_graph')) {
        return [];
    }
    $out = [];
    foreach (array_keys($want) as $eventId) {
        $rows = [];
        foreach (jeab_outlook_booking_ids_for_graph($eventId) as $bookingId) {
            $row = jeab_get_booking($store, $bookingId);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        $out[$eventId] = $rows;
    }

    return $out;
}

/**
 * Graph-id först. Annars namn och/eller gata+nummer inom ±7 dagar, samma
 * kontor, inte avbokad. Båda signalerna vinner över en. Flera på samma
 * nivå öppnar inget.
 *
 * @param list<array<string,mixed>> $rows
 * @param list<array<string,mixed>> $graphRows
 * @return array{row:?array<string,mixed>,via:string,ambiguous:bool,tied:list<int>,tier:string}
 */
function jeab_calendar_outlook_pick(array $event, array $rows, array $graphRows): array
{
    $empty = ['row' => null, 'via' => '', 'ambiguous' => false, 'tied' => [], 'tier' => ''];
    $office = jeab_selected_company();
    $liveGraph = [];
    foreach ($graphRows as $row) {
        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
            continue;
        }
        if ((string) ($row['status'] ?? '') === 'cancelled') {
            continue;
        }
        if (jeab_booking_company($row) !== $office) {
            continue;
        }
        $liveGraph[(int) $row['id']] = $row;
    }
    if (count($liveGraph) === 1) {
        return ['row' => array_values($liveGraph)[0], 'via' => 'graph', 'ambiguous' => false, 'tied' => [], 'tier' => 'graph'];
    }
    if (count($liveGraph) > 1) {
        return ['row' => null, 'via' => 'graph', 'ambiguous' => true, 'tied' => array_keys($liveGraph), 'tier' => 'graph'];
    }
    $span = jeab_calendar_outlook_span($event);
    $title = (string) ($event['title'] ?? '');
    $both = [];
    $one = [];
    foreach ($rows as $row) {
        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
            continue;
        }
        if ((string) ($row['status'] ?? '') === 'cancelled') {
            continue;
        }
        if (jeab_booking_company($row) !== $office) {
            continue;
        }
        if (!jeab_calendar_row_in_span($row, $span['from'], $span['to'])) {
            continue;
        }
        $signals = jeab_calendar_outlook_signals($title, $row);
        $id = (int) $row['id'];
        if ($signals['name'] && $signals['street']) {
            $both[$id] = $row;
        } elseif ($signals['name'] || $signals['street']) {
            $one[$id] = $row;
        }
    }
    $pool = $both !== [] ? $both : $one;
    $tier = $both !== [] ? 'both' : ($one !== [] ? 'one' : '');
    if (count($pool) === 1) {
        return ['row' => array_values($pool)[0], 'via' => 'near', 'ambiguous' => false, 'tied' => [], 'tier' => $tier];
    }
    if (count($pool) > 1) {
        return ['row' => null, 'via' => 'near', 'ambiguous' => true, 'tied' => array_keys($pool), 'tier' => $tier];
    }

    return $empty;
}

/**
 * @param array{row:?array<string,mixed>,via:string,ambiguous:bool,tied:list<int>,tier:string} $pick
 * @return array<string,mixed>
 */
function jeab_calendar_match_candidate(array $event, array $row, bool $inWavePool, array $pick): array
{
    $painted = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($row) : $row;
    $storedAssignee = trim((string) ($row['_assignee_stored'] ?? ''));
    if ($storedAssignee === '') {
        $storedAssignee = trim((string) ($row['assignee'] ?? ''));
    }
    $storedCompany = array_key_exists('_company_stored', $row)
        ? trim((string) $row['_company_stored'])
        : trim((string) ($row['company'] ?? ''));
    $address = jeab_calendar_debug_redact(trim((string) ($row['address'] ?? '')));
    $paintedAddress = jeab_calendar_debug_redact(trim((string) ($painted['address'] ?? '')));
    $customer = jeab_calendar_debug_redact(trim((string) ($row['customer_name'] ?? '')));
    $paintedCustomer = jeab_calendar_debug_redact(trim((string) ($painted['customer_name'] ?? '')));
    $title = (string) ($event['title'] ?? '');
    $notes = (string) ($row['notes'] ?? '');
    $signals = jeab_calendar_outlook_signals($title, $row);
    $verdict = jeab_calendar_match_reject($event, $row, $inWavePool);
    $id = (int) ($row['id'] ?? 0);
    $pickedId = (int) (($pick['row']['id'] ?? 0));
    $match = $pickedId > 0 && $pickedId === $id;
    $why = [];
    if (!$match) {
        $status = (string) ($row['status'] ?? '');
        if ($status === 'cancelled') {
            $why[] = 'status';
        } elseif (jeab_booking_company($row) !== jeab_selected_company()) {
            $why[] = 'office';
        } elseif (in_array($id, $pick['tied'] ?? [], true)) {
            $why[] = 'ambiguous';
        } elseif ($signals['name'] || $signals['street']) {
            $why[] = 'rank';
        } else {
            $why[] = 'text';
        }
    }
    $local = static function (string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($value))->format('c');
        } catch (Exception) {
            return $value;
        }
    };
    $out = [
        'id' => (int) ($row['id'] ?? 0),
        'customer_name' => $customer,
        'address' => $address,
        'city' => jeab_calendar_debug_redact(jeab_booking_city($row['address'] ?? null)),
        'job_type' => (string) ($row['job_type'] ?? ''),
        'job_labels' => array_values(array_map(
            static fn(string $job): string => (string) (JEAB_JOB_TYPES[$job] ?? $job),
            jeab_job_types_from_row($row)
        )),
        'assignee_raw' => jeab_calendar_debug_redact($storedAssignee),
        'assignees' => jeab_assignees_from_row($row),
        'company_raw' => jeab_calendar_debug_redact($storedCompany),
        'company' => jeab_booking_company($row),
        'starts_at' => (string) ($row['starts_at'] ?? ''),
        'ends_at' => (string) ($row['ends_at'] ?? ''),
        'starts_local' => $local((string) ($row['starts_at'] ?? '')),
        'ends_local' => $local((string) ($row['ends_at'] ?? '')),
        'status' => (string) ($row['status'] ?? ''),
        'notes_has_title' => $title !== '' && $notes !== '' && jeab_calendar_outlook_phrase_in($title, $notes),
        'name' => $signals['name'],
        'street' => $signals['street'],
        'via' => $match ? (string) ($pick['via'] ?? '') : '',
        'match' => $match,
        'why' => $match ? [] : $why,
        'overlap' => $verdict['overlap'],
        'text' => ($signals['name'] || $signals['street']) ? 'strong' : 'none',
        'who_on_booking' => $verdict['who_on_booking'],
        'in_wave_pool' => $verdict['in_wave_pool'],
    ];
    if ($paintedAddress !== '' && $paintedAddress !== $address) {
        $out['painted_address'] = $paintedAddress;
    }
    if ($paintedCustomer !== '' && $paintedCustomer !== $customer) {
        $out['painted_customer'] = $paintedCustomer;
    }

    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function jeab_calendar_debug_day_rows(JeabStore $store, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $fromSql = $from->format('Y-m-d H:i:s');
    $toSql = $to->format('Y-m-d H:i:s');
    if (method_exists($store, 'pdo')) {
        $mode = function_exists('jeab_booking_query_mode') ? jeab_booking_query_mode($store->pdo()) : ['ends_sort' => false];
        $end = function_exists('jeab_sql_booking_ends_after')
            ? jeab_sql_booking_ends_after(!empty($mode['ends_sort']))
            : "COALESCE(NULLIF(ends_at, ''), starts_at) > ?";
        $stmt = $store->pdo()->prepare(
            'SELECT id, customer_id, ' . jeab_copy_select($store->pdo(), 'bookings', jeab_copy_columns('bookings')) . ', notes, assignee, assignees, company, job_type, starts_at, ends_at, status, contact_id, created_at, updated_at
             FROM bookings
             WHERE starts_at < ? AND ' . $end . '
             ORDER BY starts_at ASC
             LIMIT 400'
        );
        $stmt->execute([$toSql, $fromSql]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $storedAssignee = trim((string) ($row['assignee'] ?? ''));
            $storedCompany = trim((string) ($row['company'] ?? ''));
            $hydrated = jeab_hydrate_booking($row);
            $hydrated['_assignee_stored'] = $storedAssignee;
            $hydrated['_company_stored'] = $storedCompany;
            $rows[] = $hydrated;
        }

        return $rows;
    }

    return jeab_bookings_between($store, $fromSql, $toSql);
}

/**
 * Läsning: för ett datum, varje Outlook-händelse och varför ingen bokning
 * öppnas. date=ÅÅÅÅ-MM-DD, who tomt eller all = alla den dagen.
 * Inga personnummer, telefoner, e-post eller anteckningstext.
 *
 * @param list<array<string,mixed>>|null $events null hämtar veckans Outlook
 * @return array<string,mixed>
 */
function jeab_calendar_match_debug(JeabStore $store, string $date, string $who = '', ?array $events = null): array
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $date = trim($date);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('date ska vara ÅÅÅÅ-MM-DD.');
    }
    $day = $parsed->setTime(0, 0);
    $next = $day->modify('+1 day');
    $who = trim($who);
    $whoAll = $who === '' || strcasecmp($who, 'all') === 0;
    $monday = jeab_monday_of($day);
    $weekTo = $monday->modify('+5 days');
    $pool = jeab_calendar_office_bookings($store, $monday, $weekTo);
    $poolIds = [];
    foreach ($pool as $row) {
        if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
            $poolIds[(int) $row['id']] = true;
        }
    }
    $graphError = '';
    if ($events === null) {
        $events = [];
        if (function_exists('jeab_gp_week_events_cached')) {
            try {
                $events = jeab_gp_week_events_cached($monday, null, null);
            } catch (Throwable $e) {
                $graphError = $e->getMessage();
                $events = [];
            }
        }
    }
    $dayEvents = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        if (!jeab_calendar_intervals_overlap(
            (string) ($event['starts_at'] ?? ''),
            (string) ($event['ends_at'] ?? ''),
            $day->format('Y-m-d H:i:s'),
            $next->format('Y-m-d H:i:s')
        )) {
            continue;
        }
        if (!$whoAll) {
            $eventWho = trim((string) ($event['who'] ?? ''));
            $resolved = jeab_resolve_person_name($eventWho, jeab_selected_company()) ?? $eventWho;
            $want = jeab_resolve_person_name($who, jeab_selected_company()) ?? $who;
            if (strcasecmp($eventWho, $who) !== 0 && strcasecmp($eventWho, $want) !== 0 && strcasecmp($resolved, $want) !== 0) {
                continue;
            }
        }
        $dayEvents[] = $event;
    }
    $spanFrom = $day->modify('-7 days');
    $spanTo = $day->modify('+8 days');
    $rows = jeab_calendar_debug_day_rows($store, $spanFrom, $spanTo);
    $graphByEvent = jeab_calendar_graph_bookings($store, array_map(
        static fn(array $event): string => (string) ($event['event_id'] ?? ''),
        $dayEvents
    ));
    $local = static function (string $value): string {
        try {
            return (new DateTimeImmutable($value))->format('c');
        } catch (Exception) {
            return $value;
        }
    };
    $outEvents = [];
    foreach ($dayEvents as $event) {
        $eventId = trim((string) ($event['event_id'] ?? ''));
        $graphRows = $eventId !== '' ? ($graphByEvent[$eventId] ?? []) : [];
        $eventRows = $rows;
        $seen = [];
        foreach ($eventRows as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $seen[(int) $row['id']] = true;
            }
        }
        foreach ($graphRows as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0 && !isset($seen[(int) $row['id']])) {
                $eventRows[] = $row;
                $seen[(int) $row['id']] = true;
            }
        }
        $pick = jeab_calendar_outlook_pick($event, $eventRows, $graphRows);
        $eventSpan = jeab_calendar_outlook_span($event);
        $candidates = [];
        foreach ($eventRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!jeab_calendar_row_in_span($row, $eventSpan['from'], $eventSpan['to'])
                && (int) ($row['id'] ?? 0) !== (int) (($pick['row']['id'] ?? 0))) {
                $isGraph = false;
                foreach ($graphRows as $graphRow) {
                    if (is_array($graphRow) && (int) ($graphRow['id'] ?? 0) === (int) ($row['id'] ?? 0)) {
                        $isGraph = true;
                        break;
                    }
                }
                if (!$isGraph) {
                    continue;
                }
            }
            $id = (int) ($row['id'] ?? 0);
            $candidates[] = jeab_calendar_match_candidate($event, $row, isset($poolIds[$id]), $pick);
        }
        $matched = (int) (($pick['row']['id'] ?? 0));
        $outEvents[] = [
            'who' => (string) ($event['who'] ?? ''),
            'event_id' => $eventId,
            'via' => $matched > 0 ? (string) ($pick['via'] ?? '') : '',
            'span_from' => $eventSpan['from']->format('Y-m-d'),
            'span_to' => $eventSpan['to']->format('Y-m-d'),
            'title' => jeab_calendar_debug_redact((string) ($event['title'] ?? '')),
            'starts_at' => (string) ($event['starts_at'] ?? ''),
            'ends_at' => (string) ($event['ends_at'] ?? ''),
            'starts_local' => $local((string) ($event['starts_at'] ?? '')),
            'ends_local' => $local((string) ($event['ends_at'] ?? '')),
            'all_day' => !empty($event['all_day']),
            'matched_id' => $matched > 0 ? $matched : null,
            'candidates' => $candidates,
        ];
    }

    return [
        'ok' => true,
        'read_only' => true,
        'date' => $day->format('Y-m-d'),
        'who' => $whoAll ? 'all' : $who,
        'company' => jeab_selected_company(),
        'tz' => $tz->getName(),
        'week_from' => $monday->format('Y-m-d H:i:s'),
        'week_to' => $weekTo->format('Y-m-d H:i:s'),
        'pool' => count($poolIds),
        'graph_error' => $graphError,
        'compares' => [
            'graph' => 'Ett sparat Graph-id som pekar på en bokning öppnas först. Kopian på en annan postlåda har ett annat id.',
            'span' => 'Utan id söks bokningar vars start ligger inom ±7 dagar från händelsen. Klockslaget behöver inte överlappa.',
            'office' => 'Bara valt kontor. Järfälla, jarfalla och jeab är samma nyckel.',
            'text' => 'Träff kräver kundnamn eller gata plus husnummer i ämnet. «huvuds väg 23» och «Huvudsväg 23» är samma. Ort, jobbnamn eller «Ps 10» räcker inte.',
            'rank' => 'Namn och gata tillsammans vinner över bara en av dem. why=rank är en svagare träff. why=ambiguous betyder flera likvärdiga, och då öppnas inget.',
            'status' => 'Avbokad rad öppnas inte, varken via id eller via text.',
            'paint' => 'Adress och kundnamn läses också från kontakten när jeab_contacts_sole_paint_booking fyller i dem.',
        ],
        'events' => $outEvents,
    ];
}

/**
 * @param list<array<string,mixed>> $outlookEvents
 * @param list<array<string,mixed>> $bookings
 * @return list<array<string,mixed>>
 */
function jeab_calendar_drop_duplicate_outlook(array $outlookEvents, array $bookings): array
{
    if ($outlookEvents === [] || $bookings === []) {
        return array_values($outlookEvents);
    }
    $out = [];
    foreach ($outlookEvents as $event) {
        if (!is_array($event)) {
            continue;
        }
        $dup = false;
        foreach ($bookings as $row) {
            if (is_array($row) && jeab_calendar_outlook_is_duplicate($event, $row)) {
                $dup = true;
                break;
            }
        }
        if (!$dup) {
            $out[] = $event;
        }
    }
    return $out;
}

/**
 * Synlig personfiltrering döljer kollegans bokning, men Outlook-kopian
 * ligger kvar som streckad ruta. Den kopian ska bli bokningens knapp.
 *
 * @param list<array<string,mixed>> $visible
 * @param list<array<string,mixed>> $office
 * @param list<array<string,mixed>> $outlookEvents
 * @return list<array<string,mixed>>
 */
function jeab_calendar_rows_with_outlook_bookings(array $visible, array $office, array $outlookEvents): array
{
    unset($office);
    $byId = [];
    foreach ($visible as $row) {
        if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
            $byId[(int) $row['id']] = $row;
        }
    }
    foreach (jeab_calendar_outlook_with_bookings($outlookEvents, []) as $event) {
        $row = jeab_calendar_outlook_booking_row($event);
        if ($row !== null) {
            $byId[(int) $row['id']] = $row;
        }
    }

    return array_values($byId);
}

function jeab_calendar_outlook_booking_row(array $event): ?array
{
    $row = $event['booking'] ?? null;
    if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
        return null;
    }
    return $row;
}

/**
 * Kopian behåller sitt ämne («Batteri, Ljusterö») men bär bokningens id
 * så klicket öppnar dialogen. Filter Alla använder samma kontorslista.
 *
 * @param list<array<string,mixed>> $outlookEvents
 * @param list<array<string,mixed>> $bookings
 * @return list<array<string,mixed>>
 */
function jeab_calendar_outlook_with_bookings(array $outlookEvents, array $bookings): array
{
    unset($bookings);
    $store = null;
    if (function_exists('jeab_store')) {
        try {
            $store = jeab_store();
        } catch (Throwable) {
            $store = null;
        }
    }
    $events = [];
    foreach ($outlookEvents as $event) {
        if (is_array($event)) {
            $events[] = $event;
        }
    }
    $rows = [];
    $graphByEvent = [];
    if ($store instanceof JeabStore && $events !== []) {
        $from = null;
        $to = null;
        foreach ($events as $event) {
            $span = jeab_calendar_outlook_span($event);
            if (!$from instanceof DateTimeImmutable || $span['from'] < $from) {
                $from = $span['from'];
            }
            if (!$to instanceof DateTimeImmutable || $span['to'] > $to) {
                $to = $span['to'];
            }
        }
        if ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable) {
            $rows = jeab_calendar_debug_day_rows($store, $from, $to);
        }
        $graphByEvent = jeab_calendar_graph_bookings($store, array_map(
            static fn(array $event): string => (string) ($event['event_id'] ?? ''),
            $events
        ));
    }
    $out = [];
    foreach ($events as $event) {
        $eventId = trim((string) ($event['event_id'] ?? ''));
        $graphRows = $eventId !== '' ? ($graphByEvent[$eventId] ?? []) : [];
        $pick = jeab_calendar_outlook_pick($event, $rows, $graphRows);
        if (is_array($pick['row'] ?? null)) {
            $event['booking'] = $pick['row'];
        }
        $out[] = $event;
    }

    return $out;
}

/**
 * @param list<array<string,mixed>> $bookings
 * @param list<array<string,mixed>> $outlookEvents
 * @return list<array<string,mixed>>
 */
function jeab_calendar_bookings_without_linked_outlook(array $bookings, array $outlookEvents): array
{
    $linked = [];
    foreach ($outlookEvents as $event) {
        if (!is_array($event)) {
            continue;
        }
        $row = jeab_calendar_outlook_booking_row($event);
        if ($row !== null && jeab_calendar_intervals_overlap(
            (string) ($event['starts_at'] ?? ''),
            (string) ($event['ends_at'] ?? ''),
            (string) ($row['starts_at'] ?? ''),
            (string) ($row['ends_at'] ?? '')
        )) {
            $linked[(int) $row['id']] = true;
        }
    }
    if ($linked === []) {
        return array_values($bookings);
    }
    $out = [];
    foreach ($bookings as $row) {
        if (!is_array($row) || isset($linked[(int) ($row['id'] ?? 0)])) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Synlig lista plus kontorets bokningar. Gäller who=all (Alla) likaväl som
 * ett personfilter. Kopian blir knappen; bokningskortet ritas inte en gång till.
 *
 * @param list<array<string,mixed>> $visible
 * @param list<array<string,mixed>> $office
 * @param list<array<string,mixed>> $outlookEvents
 * @return array{bookings:list<array<string,mixed>>,outlook:list<array<string,mixed>>}
 */
function jeab_calendar_prepare_visible(array $visible, array $office, array $outlookEvents): array
{
    unset($office);
    $outlookEvents = jeab_calendar_outlook_with_bookings(jeab_calendar_merge_outlook($outlookEvents), []);
    $byId = [];
    foreach ($visible as $row) {
        if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
            $byId[(int) $row['id']] = $row;
        }
    }
    foreach ($outlookEvents as $event) {
        if (!is_array($event)) {
            continue;
        }
        $row = jeab_calendar_outlook_booking_row($event);
        if ($row !== null) {
            $byId[(int) $row['id']] = $row;
        }
    }
    $visible = jeab_calendar_bookings_without_linked_outlook(array_values($byId), $outlookEvents);

    return ['bookings' => $visible, 'outlook' => $outlookEvents];
}

/**
 * Död Outlook först, bokningskort sen, kopian som är knappen sist.
 * Sist i DOM ligger över och tar klicket.
 *
 * @param list<array<string,mixed>> $entries
 * @return list<array<string,mixed>>
 */
function jeab_calendar_paint_order(array $entries): array
{
    $dead = [];
    $cards = [];
    $linked = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (($entry['kind'] ?? '') === 'outlook') {
            $event = is_array($entry['event'] ?? null) ? $entry['event'] : [];
            if (jeab_calendar_outlook_booking_row($event) !== null) {
                $linked[] = $entry;
            } else {
                $dead[] = $entry;
            }
            continue;
        }
        $cards[] = $entry;
    }
    return array_merge($dead, $cards, $linked);
}

function jeab_calendar_filter_next(?string $whoFilter, ?string $jobFilter, string $kind, string $value, ?string $company = null): array
{
    unset($company);
    if ($kind === 'who') {
        if ($whoFilter === $value && $jobFilter === null) {
            return ['who' => 'all', 'job' => 'all'];
        }
        return ['who' => $value, 'job' => 'all'];
    }
    if ($jobFilter === $value && $whoFilter === null) {
        return ['who' => 'all', 'job' => 'all'];
    }
    return ['who' => 'all', 'job' => $value];
}

function jeab_booking_matches_filter(array $row, ?string $who, ?string $job): bool
{
    if ($who !== null) {
        $want = jeab_resolve_person_name($who, jeab_booking_company($row));
        if ($want === null || !in_array($want, jeab_assignees_from_row($row), true)) {
            return false;
        }
    }
    if ($job !== null && !jeab_booking_has_job($row, $job)) {
        return false;
    }
    return true;
}

function jeab_filter_bookings(array $rows, ?string $who, ?string $job): array
{
    if ($who === null && $job === null) {
        return $rows;
    }
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => jeab_booking_matches_filter($row, $who, $job)
    ));
}

function jeab_bookings_url(array $extra = []): string
{
    $cacheKey = json_encode([
        $extra,
        (string) ($_GET['w'] ?? ''),
        (string) ($_GET['who'] ?? ''),
        (string) ($_GET['job'] ?? ''),
        (string) ($_GET['co'] ?? ''),
        (string) ($_GET['view'] ?? ''),
        (string) ($_GET['d'] ?? ''),
        (string) ($_GET['ws'] ?? ''),
        (string) ($_SESSION['jeab_company'] ?? ''),
        function_exists('jeab_auth_user_fingerprint') ? (string) jeab_auth_user_fingerprint() : '',
    ], JSON_UNESCAPED_UNICODE);
    if (is_string($cacheKey)) {
        $hit = $GLOBALS['_JEAB_BOOK_URL'][$cacheKey] ?? null;
        if (is_string($hit)) {
            return $hit;
        }
    }
    $q = ['p' => 'bookings'];
    $w = (int) ($_GET['w'] ?? 0);
    if (array_key_exists('w', $extra)) {
        $w = (int) $extra['w'];
        unset($extra['w']);
    }
    if ($w !== 0) {
        $q['w'] = $w;
    }
    $whoPassed = array_key_exists('who', $extra);
    $jobPassed = array_key_exists('job', $extra);
    $whoRaw = $whoPassed ? $extra['who'] : null;
    $jobRaw = $jobPassed ? $extra['job'] : null;
    $company = array_key_exists('co', $extra) ? $extra['co'] : jeab_selected_company();
    $view = array_key_exists('view', $extra) ? (string) $extra['view'] : jeab_booking_view();
    $view = in_array($view, ['month', 'day'], true) ? $view : '';
    if ($view === 'day') {
        // Dagvyn styrs av d, inte w.
        unset($q['w']);
        if (!array_key_exists('d', $extra) || (string) $extra['d'] === '') {
            $extra['d'] = jeab_calendar_focus_day()->format('Y-m-d');
        }
    } else {
        unset($extra['d']);
    }
    unset($extra['who'], $extra['job'], $extra['co'], $extra['view']);
    if ($whoPassed || $jobPassed) {
        $q['who'] = $whoPassed ? jeab_filter_query_value($whoRaw, 'who') : 'all';
        $q['job'] = $jobPassed ? jeab_filter_query_value($jobRaw, 'job') : 'all';
    } elseif (function_exists('jeab_filter_url_params')) {
        foreach (jeab_filter_url_params(jeab_booking_who_filter(), jeab_booking_job_filter()) as $key => $value) {
            $q[$key] = $value;
        }
    } else {
        $who = jeab_booking_who_filter();
        $job = jeab_booking_job_filter();
        if (is_string($who) && $who !== '') {
            $q['who'] = $who;
        }
        if (is_string($job) && $job !== '') {
            $q['job'] = $job;
        }
    }
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    $q['co'] = $company;
    if ($view !== '') {
        $q['view'] = $view;
    }
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $q[$key] = $value;
    }
    $url = jeab_url('index.php?' . http_build_query($q));
    if (is_string($cacheKey)) {
        if (!isset($GLOBALS['_JEAB_BOOK_URL']) || !is_array($GLOBALS['_JEAB_BOOK_URL'])) {
            $GLOBALS['_JEAB_BOOK_URL'] = [];
        }
        $GLOBALS['_JEAB_BOOK_URL'][$cacheKey] = $url;
    }

    return $url;
}

function jeab_month_covers_day(string $startsAt, string $endsAt, DateTimeImmutable $day): bool
{
    try {
        $start = (new DateTimeImmutable($startsAt))->setTime(0, 0);
        $end = (new DateTimeImmutable($endsAt))->setTime(0, 0);
    } catch (Exception) {
        return false;
    }
    $d = $day->setTime(0, 0);
    return $d >= $start && $d < $end;
}

function jeab_month_clock(string $at): string
{
    try {
        $dt = new DateTimeImmutable($at);
    } catch (Exception) {
        return '';
    }
    return $dt->format('i') === '00' ? $dt->format('H') : $dt->format('H:i');
}

function jeab_month_time_label(string $startsAt, string $endsAt): string
{
    $start = jeab_month_clock($startsAt);
    $end = jeab_month_clock($endsAt);
    if ($start === '' || $end === '') {
        return '';
    }
    return $start . '–' . $end;
}

function jeab_month_place_name(array $row): string
{
    if (function_exists('jeab_contacts_sole_paint_booking')) {
        $row = jeab_contacts_sole_paint_booking($row);
    }
    $address = trim((string) ($row['address'] ?? ''));
    if ($address !== '') {
        $street = str_contains($address, ',') ? trim(explode(',', $address, 2)[0]) : $address;
        $street = trim((string) preg_replace('/\s+\d+\S*$/u', '', $street));
        if ($street !== '') {
            return $street;
        }
    }
    return trim((string) ($row['customer_name'] ?? ''));
}

function jeab_month_sol_label(array $row): string
{
    $job = JEAB_JOB_TYPES['sol'] ?? 'Sol';
    $place = jeab_month_place_name($row);
    return $place !== '' ? $job . ' ' . $place : $job;
}

function jeab_month_booking_label(array $row, bool $allDay): string
{
    if (function_exists('jeab_contacts_sole_paint_booking')) {
        $row = jeab_contacts_sole_paint_booking($row);
    }
    $name = trim((string) ($row['customer_name'] ?? ''));
    if ($name === '') {
        $name = jeab_month_place_name($row);
    }
    if ($name === '') {
        $name = jeab_job_types_label($row);
        if ($name === '') {
            $name = 'Bokning';
        }
    }
    if ($allDay) {
        return $name;
    }
    $time = jeab_month_time_label((string) $row['starts_at'], (string) $row['ends_at']);
    return $time !== '' ? $time . ' ' . $name : $name;
}

function jeab_month_outlook_label(array $event, bool $allDay): string
{
    $name = trim((string) ($event['title'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($event['who'] ?? ''));
    }
    if ($name === '') {
        $name = 'Outlook';
    }
    if ($allDay) {
        return $name;
    }
    $time = jeab_month_time_label((string) ($event['starts_at'] ?? ''), (string) ($event['ends_at'] ?? ''));
    return $time !== '' ? $time . ' ' . $name : $name;
}

function jeab_month_sol_bars(array $bookings, DateTimeImmutable $weekMonday): array
{
    $out = [];
    foreach ($bookings as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!jeab_booking_has_job($row, 'sol')) {
            continue;
        }
        if (!jeab_booking_is_all_day((string) $row['starts_at'], (string) $row['ends_at'])) {
            continue;
        }
        $place = jeab_booking_allday_placement((string) $row['starts_at'], (string) $row['ends_at'], $weekMonday);
        if (!$place) {
            continue;
        }
        $out[] = [
            'row' => $row,
            'col' => max(1, (int) $place['col'] - 1),
            'span' => max(1, (int) $place['span']),
        ];
    }
    return $out;
}

function jeab_month_day_items(array $bookings, array $outlookEvents, DateTimeImmutable $day): array
{
    $out = [];
    $key = $day->format('Y-m-d');
    foreach ($bookings as $row) {
        if (!is_array($row)) {
            continue;
        }
        $starts = (string) ($row['starts_at'] ?? '');
        $ends = (string) ($row['ends_at'] ?? '');
        $allDay = jeab_booking_is_all_day($starts, $ends);
        $sol = jeab_booking_has_job($row, 'sol');
        if ($sol && $allDay) {
            continue;
        }
        if ($allDay) {
            if (jeab_month_covers_day($starts, $ends, $day)) {
                $out[] = ['kind' => 'booking', 'row' => $row, 'allday' => true];
            }
            continue;
        }
        try {
            $startDay = (new DateTimeImmutable($starts))->format('Y-m-d');
        } catch (Exception) {
            continue;
        }
        if ($startDay === $key) {
            $out[] = ['kind' => 'booking', 'row' => $row, 'allday' => false];
        }
    }
    foreach ($outlookEvents as $event) {
        if (!is_array($event)) {
            continue;
        }
        $starts = (string) ($event['starts_at'] ?? '');
        $ends = (string) ($event['ends_at'] ?? '');
        $allDay = !empty($event['all_day']) || jeab_booking_is_all_day($starts, $ends);
        if ($allDay) {
            if (jeab_month_covers_day($starts, $ends, $day)) {
                $out[] = ['kind' => 'outlook', 'event' => $event, 'allday' => true];
            }
            continue;
        }
        try {
            $startDay = (new DateTimeImmutable($starts))->format('Y-m-d');
        } catch (Exception) {
            continue;
        }
        if ($startDay === $key) {
            $out[] = ['kind' => 'outlook', 'event' => $event, 'allday' => false];
        }
    }
    return $out;
}

function jeab_month_legend_html(): string
{
    return '<div class="mv-legend" aria-label="Färg">'
        . '<span class="mv-leg is-laddbox">Elbilsladdare</span>'
        . '<span class="mv-leg is-batteri">Batteri</span>'
        . '<span class="mv-leg is-sol">Sol</span>'
        . '</div>';
}

function jeab_text_or_null(mixed $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function jeab_compose_site_notes(string $installWhat, string $installNote, string $reminderNote): string
{
    $what = trim($installWhat);
    $install = trim($installNote);
    $reminder = trim($reminderNote);
    $lines = [];
    if ($what !== '') {
        $lines[] = $what;
    }
    if ($what !== '' && $install !== '') {
        $lines[] = '';
    }
    if ($install !== '') {
        $lines[] = $install;
    }
    if ($reminder !== '') {
        $lines[] = $reminder;
    }
    return implode("\n", $lines);
}

function jeab_booking_site_notes_from_sources(array $booking, ?array $purchase, ?array $reminder): string
{
    $what = '';
    $install = '';
    if (is_array($purchase)) {
        $what = function_exists('jeab_ordrar_install_what')
            ? jeab_ordrar_install_what($purchase)
            : trim((string) ($purchase['install_what'] ?? ''));
        $install = function_exists('jeab_ordrar_install_note')
            ? jeab_ordrar_install_note($purchase)
            : trim((string) ($purchase['install_note'] ?? ''));
    }
    $reminderNote = '';
    if (is_array($reminder) && function_exists('jeab_reminder_notes_text')) {
        $reminderNote = jeab_reminder_notes_text($reminder);
    }
    $composed = jeab_compose_site_notes($what, $install, $reminderNote);
    if ($composed !== '') {
        return $composed;
    }
    return trim((string) ($booking['notes'] ?? ''));
}

function jeab_booking_site_notes(array $booking, ?JeabStore $store = null): string
{
    $purchase = function_exists('jeab_purchase_by_booking')
        ? jeab_purchase_by_booking((int) ($booking['id'] ?? 0))
        : null;
    $reminder = null;
    if ($store === null && function_exists('jeab_store')) {
        try {
            $store = jeab_store();
        } catch (Throwable) {
            $store = null;
        }
    }
    if ($store instanceof JeabStore) {
        if (is_array($purchase)) {
            $reminder = jeab_get_reminder($store, (int) ($purchase['reminder_id'] ?? 0));
        }
        if (!is_array($reminder)) {
            $reminder = $store->findOpenReminderByBooking((int) ($booking['id'] ?? 0));
        }
    }
    return jeab_booking_site_notes_from_sources($booking, $purchase, is_array($reminder) ? $reminder : null);
}

function jeab_normalize_booking_phone(mixed $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $norm = jeab_norm_phone($value);
    return $norm !== '' ? $norm : null;
}

function jeab_booking_details_from_input(array $input): array
{
    return [
        'email' => jeab_text_or_null($input['email'] ?? null),
        'phone' => jeab_normalize_booking_phone($input['phone'] ?? null),
        'address' => jeab_text_or_null($input['address'] ?? null),
        'notes' => jeab_text_or_null($input['notes'] ?? null),
    ];
}

function jeab_normalize_job_type(?string $job): ?string
{
    if ($job === null) {
        return null;
    }
    $job = strtolower(trim($job));
    return isset(JEAB_JOB_TYPES[$job]) ? $job : null;
}

/** @return list<string> */
function jeab_parse_job_types(mixed $raw): array
{
    $keys = [];
    if (is_array($raw)) {
        foreach ($raw as $item) {
            if (is_array($item)) {
                $keys = array_merge($keys, jeab_parse_job_types($item));
                continue;
            }
            $keys = array_merge($keys, jeab_parse_job_types((string) $item));
        }
    } else {
        $text = trim((string) $raw);
        if ($text !== '') {
            foreach (preg_split('/\s*,\s*/', $text) ?: [] as $item) {
                $key = jeab_normalize_job_type($item !== '' ? $item : null);
                if ($key !== null) {
                    $keys[] = $key;
                }
            }
        }
    }
    $picked = [];
    foreach (array_keys(JEAB_JOB_TYPES) as $key) {
        if (in_array($key, $keys, true)) {
            $picked[] = $key;
        }
    }
    return $picked;
}

/** @return list<string> */
function jeab_job_types_from_row(array $row): array
{
    return jeab_parse_job_types($row['job_type'] ?? null);
}

function jeab_job_types_join(array $jobs): ?string
{
    $jobs = jeab_parse_job_types($jobs);
    return $jobs !== [] ? implode(',', $jobs) : null;
}

function jeab_job_types_label(array $row): string
{
    $labels = [];
    foreach (jeab_job_types_from_row($row) as $job) {
        $labels[] = JEAB_JOB_TYPES[$job];
    }
    return implode(' · ', $labels);
}

function jeab_booking_has_job(array $row, string $job): bool
{
    $job = jeab_normalize_job_type($job);
    return $job !== null && in_array($job, jeab_job_types_from_row($row), true);
}

/**
 * Klick i kalendern med produktfilter.
 * Batteri 07:00–16:00, laddbox halvdag 07:00–12:00 / 12:00–16:00, sol fem vardagar.
 * Sparade bokningar lämnas orörda.
 *
 * @return array{0: string, 1: string}|null
 */
function jeab_booking_product_click_span(?string $job, string $startIso): ?array
{
    $job = jeab_normalize_job_type($job);
    $day = substr($startIso, 0, 10);
    if ($job === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        return null;
    }
    if ($job === 'laddbox') {
        $hour = (int) substr($startIso . 'T00', 11, 2);
        if ($hour < 12) {
            return [$day . 'T07:00', $day . 'T12:00'];
        }
        return [$day . 'T12:00', $day . 'T16:00'];
    }
    if ($job === 'batteri') {
        return [$day . 'T07:00', $day . 'T16:00'];
    }
    if ($job === 'sol') {
        $clicked = new DateTimeImmutable($day . ' 00:00:00');
        $monday = $clicked->modify('-' . ((int) $clicked->format('N') - 1) . ' days')->setTime(0, 0);
        $saturday = $monday->modify('+5 days');
        return [$monday->format('Y-m-d') . 'T00:00', $saturday->format('Y-m-d') . 'T00:00'];
    }
    return null;
}

/** @return list<string> Klockslag HH:MM i halvtimmessteg. */
function jeab_booking_half_hour_labels(): array
{
    $labels = [];
    for ($minutes = 0; $minutes < 24 * 60; $minutes += 30) {
        $labels[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
    return $labels;
}

/**
 * Namn som redan har en bokning som överlappar intervallet.
 * Varning, inte spärr: den nya tiden sparas ändå.
 *
 * @param list<string> $names
 * @return list<string>
 */
function jeab_booking_assignee_overlap(JeabStore $store, array $names, string $starts, string $ends, int $ignoreId = 0): array
{
    $want = [];
    foreach ($names as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $want[strtolower($name)] = $name;
        }
    }
    if ($want === [] || $starts === '' || $ends === '') {
        return [];
    }
    $hits = [];
    foreach ($store->bookingsBetween($starts, $ends) as $row) {
        if (!is_array($row) || (int) ($row['id'] ?? 0) === $ignoreId) {
            continue;
        }
        if (($row['status'] ?? 'booked') === 'cancelled') {
            continue;
        }
        foreach (jeab_assignees_from_row($row) as $name) {
            $key = strtolower(trim((string) $name));
            if ($key !== '' && isset($want[$key])) {
                $hits[$key] = $want[$key];
            }
        }
    }
    return array_values($hits);
}

function jeab_booking_note_overlap(JeabStore $store, array $names, string $starts, string $ends, int $ignoreId = 0): void
{
    unset($GLOBALS['_JEAB_BOOKING_OVERLAP_NOTE']);
    if (jeab_booking_assignee_overlap($store, $names, $starts, $ends, $ignoreId) !== []) {
        $GLOBALS['_JEAB_BOOKING_OVERLAP_NOTE'] = 'Samma person är redan bokad då.';
    }
}

function jeab_booking_assert_schedule(string $starts, string $ends): void
{
    if (jeab_booking_is_all_day($starts, $ends)) {
        return;
    }
    try {
        $start = new DateTimeImmutable($starts);
        $end = new DateTimeImmutable($ends);
    } catch (Exception) {
        throw new InvalidArgumentException('När ska det ske?');
    }
    $startMin = (int) $start->format('i');
    $endMin = (int) $end->format('i');
    if (!in_array($startMin, [0, 30], true) || !in_array($endMin, [0, 30], true)) {
        throw new InvalidArgumentException('Välj hel- eller halvtimme.');
    }
    $minutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
    if ($minutes < 60) {
        throw new InvalidArgumentException('Bokningen måste vara minst en timme.');
    }
}

function jeab_malmo_names_block_wednesday(array $names): bool
{
    foreach ($names as $name) {
        if ($name === 'Isaq' || $name === 'Abol') {
            return true;
        }
    }
    return false;
}

/**
 * Malmö-sol is Monday–Tuesday or Thursday–Friday. Wednesday is never a work day
 * for Isaq and Abol (they close Tuesday and move the scaffold).
 *
 * @return array{starts_at:string,ends_at:string}
 */
function jeab_malmo_sol_span_from(string $starts): array
{
    $tz = new DateTimeZone('Europe/Stockholm');
    $day = new DateTimeImmutable(substr($starts, 0, 10) . ' 00:00:00', $tz);
    $n = (int) $day->format('N');
    if ($n === 2 || $n === 5) {
        $day = $day->modify('-1 day');
    } elseif ($n === 3) {
        $day = $day->modify('+1 day');
    }
    for ($i = 0; $i < 8; $i++) {
        $pair = jeab_ordrar_meab_sol_pair_from_day($day->modify('+' . $i . ' days'));
        if (is_array($pair)) {
            return $pair;
        }
    }
    throw new InvalidArgumentException('Isaq och Abol bokas inte på en onsdag.');
}

/**
 * One path for who stays on a Malmö job. Extra names are dropped.
 * Sol spans are forced onto Monday–Tuesday or Thursday–Friday.
 *
 * @param list<string> $names
 * @return array{names:list<string>,starts:string,ends:string}
 */
function jeab_malmo_lock_booking(string $company, mixed $job, array $names, string $starts, string $ends): array
{
    if ($company !== 'malmo') {
        return ['names' => $names, 'starts' => $starts, 'ends' => $ends];
    }
    $jobs = jeab_parse_job_types($job);
    $kind = $jobs[0] ?? '';
    $crew = $kind !== '' ? jeab_malmo_job_crew($kind) : [];
    if ($crew !== []) {
        $names = $crew;
    }
    if ($kind === 'sol') {
        $pair = jeab_malmo_sol_span_from($starts);
        $starts = $pair['starts_at'];
        $ends = $pair['ends_at'];
    } elseif (jeab_malmo_names_block_wednesday($names) && jeab_malmo_span_hits_wednesday($starts, $ends)) {
        throw new InvalidArgumentException('Isaq och Abol bokas inte på en onsdag.');
    }
    return ['names' => $names, 'starts' => $starts, 'ends' => $ends];
}

function jeab_create_booking(
    JeabStore $store,
    string $customerName,
    array|string $assignees,
    string $startsAt,
    ?string $endsAt = null,
    mixed $jobType = null,
    array $details = [],
    bool $notifyCustomer = true
): array {
    $explicitCompany = jeab_normalize_company($details['company'] ?? null);
    $names = jeab_assignees_from_input($details);
    if (!$names) {
        $names = $assignees;
    }
    $unscoped = jeab_parse_assignees($names);
    $company = $explicitCompany ?? jeab_infer_company_from_names($unscoped) ?? jeab_selected_company();
    $names = jeab_parse_assignees($names, $company);
    $names = jeab_require_installers($names);
    $starts = jeab_parse_datetime($startsAt);
    if (!$starts) {
        throw new InvalidArgumentException('När ska det ske?');
    }
    $ends = jeab_parse_datetime($endsAt);
    if (!$ends) {
        $ends = (new DateTimeImmutable($starts))->modify('+60 minutes')->format('Y-m-d H:i:s');
    }
    [$starts, $ends] = jeab_booking_clamp_allday($starts, $ends);
    $locked = jeab_malmo_lock_booking($company, $jobType, $names, $starts, $ends);
    $names = jeab_require_installers($locked['names']);
    $starts = $locked['starts'];
    $ends = $locked['ends'];
    jeab_booking_assert_schedule($starts, $ends);
    jeab_booking_note_overlap($store, $names, $starts, $ends, 0);
    unset($GLOBALS['_JEAB_BOOKING_OUTLOOK_NOTE']);
    $inheritContact = trim((string) ($details['contact_id'] ?? ''));
    if ($inheritContact === '') {
        $fromReminder = (int) ($details['reminder_id'] ?? 0);
        if ($fromReminder > 0) {
            $linkedReminder = $store->getReminder($fromReminder);
            if (is_array($linkedReminder)) {
                $inheritContact = trim((string) ($linkedReminder['contact_id'] ?? ''));
            }
        }
    }
    $now = jeab_now();
    $testSubmission = !empty($details['is_test']) || !empty($details['test'])
        || (function_exists('jeab_test_submission_marked') && jeab_test_submission_marked($details));
    $details = jeab_booking_details_from_input($details);
    if (function_exists('jeab_contacts_strict_prepare_link')) {
        $inheritContact = jeab_contacts_strict_prepare_link('booking', $inheritContact, [
            'customer_name' => $customerName,
            'email' => (string) ($details['email'] ?? ''),
            'phone' => (string) ($details['phone'] ?? ''),
            'address' => (string) ($details['address'] ?? ''),
            'contact_id' => $inheritContact,
        ]);
    }
    $storeName = $customerName;
    $storeEmail = $details['email'];
    $storePhone = $details['phone'];
    $storeAddress = $details['address'];
    if (!function_exists('jeab_contacts_copies_hold') && is_file(__DIR__ . '/contacts_copies.php')) {
        require_once __DIR__ . '/contacts_copies.php';
    }
    if ($inheritContact !== '' && function_exists('jeab_contacts_copies_hold') && jeab_contacts_copies_hold() && function_exists('jeab_contacts_copies_placeholder_customer')) {
        require_once __DIR__ . '/customers_contact_key.php';
        $customer = jeab_customer_for_contact($store, $inheritContact);
        $storeName = '';
        $storeEmail = null;
        $storePhone = null;
        $storeAddress = null;
    } else {
        $customer = jeab_find_or_create_customer($store, $customerName);
        $storeName = (string) ($customer['name'] ?? $customerName);
    }
    $row = $store->insertBooking([
        'customer_id' => $customer['id'],
        'customer_name' => $storeName,
        'email' => $storeEmail,
        'phone' => $storePhone,
        'address' => $storeAddress,
        'notes' => $details['notes'],
        'contact_id' => $inheritContact !== '' ? $inheritContact : null,
        'assignees' => $names,
        'assignee' => jeab_assignees_join($names),
        'company' => $company,
        'job_type' => jeab_job_types_join(jeab_parse_job_types($jobType)),
        'starts_at' => $starts,
        'ends_at' => $ends,
        'status' => 'booked',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    if (function_exists('jeab_contacts_sole_link_record') && jeab_contacts_sole()) {
        $linked = jeab_contacts_sole_link_record($store, 'booking', $row);
        if (is_array($linked)) {
            $row = $linked;
        }
    }
    if (trim((string) ($row['contact_id'] ?? '')) === '' && function_exists('jeab_contacts_strict_assert_link') && jeab_contacts_strict() && function_exists('jeab_contacts_sole') && jeab_contacts_sole()) {
        $newId = (int) ($row['id'] ?? 0);
        if ($newId > 0 && method_exists($store, 'deleteBooking')) {
            try {
                $store->deleteBooking($newId);
            } catch (Throwable) {
            }
        }
        jeab_contacts_strict_assert_link('booking', '');
    }
    jeab_calendar_log_append('booked', $row, $now, 'web');
    jeab_remember_assignees($names);
    if (!$testSubmission) {
        jeab_fire_webhook($store, 'booking.created', jeab_booking_payload($row));
        try {
            jeab_calendar_sync_outlook($store, $row);
        } catch (Throwable $e) {
            jeab_calendar_sync_outlook_log($e);
            $GLOBALS['_JEAB_BOOKING_OUTLOOK_NOTE'] = 'Outlook-synken misslyckades.';
        }
    }
    // $notifyCustomer skickar inte mejl. Bekräftelsen väntar i jeab_sms_tick
    // tills bokningen varit orörd minst två timmar (updated_at). Flaggan
    // finns kvar så befintliga anrop (hook, ordrar) inte går sönder.
    unset($notifyCustomer);
    return $row;
}

function jeab_update_booking(JeabStore $store, int $id, array $fields): array
{
    $row = $store->getBooking($id);
    if (!$row) {
        throw new InvalidArgumentException('Bokningen finns inte.');
    }
    if (function_exists('jeab_company_allowed') && !jeab_company_allowed(jeab_booking_company($row))) {
        throw new InvalidArgumentException('Inte behörig.');
    }
    if ($row['status'] === 'cancelled') {
        throw new InvalidArgumentException('Bokningen är avbokad.');
    }

    $company = jeab_normalize_company($fields['company'] ?? null) ?? jeab_booking_company($row);
    if (function_exists('jeab_company_allowed') && !jeab_company_allowed($company)) {
        throw new InvalidArgumentException('Inte behörig.');
    }
    $customerName = array_key_exists('customer', $fields) ? trim((string) $fields['customer']) : $row['customer_name'];
    if (array_key_exists('assignees', $fields) || array_key_exists('assignee', $fields)) {
        $names = jeab_require_installers(jeab_parse_assignees(jeab_assignees_from_input($fields), $company));
    } else {
        $names = jeab_assignees_from_row($row);
    }
    $starts = array_key_exists('starts_at', $fields)
        ? jeab_parse_datetime((string) $fields['starts_at'])
        : $row['starts_at'];
    $ends = $row['ends_at'];
    if (array_key_exists('ends_at', $fields)) {
        $parsedEnd = jeab_parse_datetime((string) ($fields['ends_at'] ?? ''));
        if ($parsedEnd) {
            $ends = $parsedEnd;
        }
    }
    if (array_key_exists('job_types', $fields) || array_key_exists('job_type', $fields)) {
        $job = jeab_job_types_join(jeab_parse_job_types($fields['job_types'] ?? $fields['job_type'] ?? null));
    } else {
        $job = $row['job_type'];
    }

    $linkedContact = trim((string) ($row['contact_id'] ?? ''));
    if (trim((string) $customerName) === '' && $linkedContact === '') {
        throw new InvalidArgumentException('Vem är bokningen åt?');
    }
    if (!$starts) {
        throw new InvalidArgumentException('När ska det ske?');
    }
    if (!$ends) {
        $ends = (new DateTimeImmutable($starts))->modify('+60 minutes')->format('Y-m-d H:i:s');
    }
    [$starts, $ends] = jeab_booking_clamp_allday($starts, $ends);
    $timeTouched = array_key_exists('starts_at', $fields) || array_key_exists('ends_at', $fields);
    $staffTouched = array_key_exists('assignees', $fields) || array_key_exists('assignee', $fields)
        || array_key_exists('job_types', $fields) || array_key_exists('job_type', $fields);
    if ($company === 'malmo' && $staffTouched) {
        $locked = jeab_malmo_lock_booking($company, $job, $names, $starts, $ends);
        if ($locked['starts'] !== $starts || $locked['ends'] !== $ends) {
            $timeTouched = true;
        }
        $names = jeab_require_installers($locked['names']);
        $starts = $locked['starts'];
        $ends = $locked['ends'];
    } elseif ($company === 'malmo' && $timeTouched) {
        $kind = jeab_parse_job_types($job)[0] ?? '';
        $oldHit = jeab_malmo_span_hits_wednesday((string) $row['starts_at'], (string) ($row['ends_at'] ?? ''));
        $newHit = jeab_malmo_span_hits_wednesday($starts, $ends);
        if (!$oldHit && $newHit && ($kind === 'sol' || jeab_malmo_names_block_wednesday($names))) {
            if ($kind === 'sol') {
                $pair = jeab_malmo_sol_span_from($starts);
                $starts = $pair['starts_at'];
                $ends = $pair['ends_at'];
            } else {
                throw new InvalidArgumentException('Isaq och Abol bokas inte på en onsdag.');
            }
        }
    }
    if ($timeTouched) {
        jeab_booking_assert_schedule($starts, $ends);
    }
    if ($timeTouched || $staffTouched) {
        jeab_booking_note_overlap($store, $names, $starts, $ends, $id);
    } else {
        unset($GLOBALS['_JEAB_BOOKING_OVERLAP_NOTE']);
    }
    unset($GLOBALS['_JEAB_BOOKING_OUTLOOK_NOTE']);

    $details = jeab_booking_details_from_input($fields);
    $picked = function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_booking_pick')
        ? jeab_contacts_sole_booking_pick($row)
        : null;
    $soleContact = is_array($picked) && is_array($picked['contact'] ?? null) ? $picked['contact'] : null;
    if (is_array($soleContact) && function_exists('jeab_reminder_save_contact_customer')) {
        $patch = [];
        if (array_key_exists('customer', $fields) && trim((string) $fields['customer']) !== '') {
            $patch['name'] = trim((string) $fields['customer']);
        }
        if (array_key_exists('email', $fields)) {
            $patch['email'] = trim((string) ($details['email'] ?? ''));
        }
        if (array_key_exists('phone', $fields)) {
            $patch['phone'] = trim((string) ($details['phone'] ?? ''));
        }
        if (array_key_exists('address', $fields)) {
            $parsed = jeab_address_parts((string) ($details['address'] ?? ''));
            $street = $parsed['street'] !== '' ? $parsed['street'] : trim((string) ($details['address'] ?? ''));
            if ($street !== '') {
                $patch['street'] = $street;
            }
            if ($parsed['city'] !== '') {
                $patch['city'] = $parsed['city'];
            }
            if ($parsed['postal'] !== '') {
                $patch['postal'] = $parsed['postal'];
            }
        }
        if ($patch !== []) {
            jeab_reminder_save_contact_customer((string) ($soleContact['id'] ?? ''), $patch);
        }
    }
    $storedName = trim((string) ($row['customer_name'] ?? ''));
    if ($linkedContact !== '' && ($storedName === '' || trim((string) $customerName) === '')) {
        if (!function_exists('jeab_contacts_copies_placeholder_customer') && is_file(__DIR__ . '/contacts_copies.php')) {
            require_once __DIR__ . '/contacts_copies.php';
        }
        require_once __DIR__ . '/customers_contact_key.php';
        $customer = jeab_customer_for_contact($store, $linkedContact);
    } else {
        $customer = jeab_find_or_create_customer($store, $customerName);
    }
    $timeChanged = (string) $row['starts_at'] !== (string) $starts || (string) $row['ends_at'] !== (string) $ends;
    $prevStarts = (string) $row['starts_at'];
    $prevEnds = (string) ($row['ends_at'] ?? '');
    $saveFields = [
        'customer_id' => $customer['id'],
        'customer_name' => is_array($soleContact) ? ($row['customer_name'] ?? null) : $customer['name'],
        'email' => is_array($soleContact) ? ($row['email'] ?? null) : (array_key_exists('email', $fields) ? $details['email'] : ($row['email'] ?? null)),
        'phone' => is_array($soleContact) ? ($row['phone'] ?? null) : (array_key_exists('phone', $fields) ? $details['phone'] : ($row['phone'] ?? null)),
        'address' => is_array($soleContact) ? ($row['address'] ?? null) : (array_key_exists('address', $fields) ? $details['address'] : ($row['address'] ?? null)),
        'notes' => array_key_exists('notes', $fields) ? $details['notes'] : ($row['notes'] ?? null),
        'assignees' => $names,
        'assignee' => jeab_assignees_join($names),
        'company' => $company,
        'job_type' => $job,
        'starts_at' => $starts,
        'ends_at' => $ends,
        'updated_at' => jeab_now(),
    ];
    if (is_array($picked) && !empty($picked['repoint'])) {
        $nextContact = trim((string) ($picked['id'] ?? ''));
        if ($nextContact !== '') {
            $saveFields['contact_id'] = $nextContact;
        }
    }
    $row = $store->saveBooking($id, $saveFields);
    if ($timeChanged) {
        jeab_calendar_log_append('rescheduled', $row, null, 'web', [
            'prev_starts_at' => $prevStarts,
            'prev_ends_at' => $prevEnds,
        ]);
        if (function_exists('jeab_booking_mark_move_mail')) {
            jeab_booking_mark_move_mail((int) $row['id'], (string) $row['starts_at'], $row);
        }
    }
    if (function_exists('jeab_ordrar_sync_bokad_tid_from_booking')) {
        jeab_ordrar_sync_bokad_tid_from_booking($row);
    }
    jeab_remember_assignees($names);
    jeab_fire_webhook($store, 'booking.updated', jeab_booking_payload($row));
    try {
        jeab_calendar_sync_outlook($store, $row);
    } catch (Throwable $e) {
        jeab_calendar_sync_outlook_log($e);
        $GLOBALS['_JEAB_BOOKING_OUTLOOK_NOTE'] = 'Outlook-synken misslyckades.';
    }
    // Ingen kundbekräftelse vid ändring. updated_at flyttas, så sms-cronens
    // tvåtimmarsklocka startar om. Har bekräftelsemejlet redan gått skickas
    // det inte igen. Med BOOKING_MOVE_MAIL och ändrad tid skrivs bara en
    // väntande flyttpost. En vitlistad testbokning
    // (BOOKING_MOVE_MAIL_TEST_IDS) skrivs även när flaggan är av.
    // Samma sms-cron som skickar bekräftelserna tar
    // sluttiden. Sparningen skickar inget mejl.
    return $row;
}

function jeab_cancel_booking(JeabStore $store, int $id, ?string $logSource = 'web', ?array $meta = null): array
{
    $row = $store->getBooking($id);
    if (!$row) {
        throw new InvalidArgumentException('Bokningen finns inte.');
    }
    if (function_exists('jeab_company_allowed') && !jeab_company_allowed(jeab_booking_company($row))) {
        throw new InvalidArgumentException('Inte behörig.');
    }
    $alreadyCancelled = ($row['status'] ?? '') === 'cancelled';
    $row = $store->saveBooking($id, [
        'status' => 'cancelled',
        'updated_at' => jeab_now(),
    ]);
    if (!$alreadyCancelled) {
        jeab_calendar_log_append('cancelled', $row, null, $logSource, $meta);
    }
    if (function_exists('jeab_booking_mark_move_mail')) {
        jeab_booking_mark_move_mail((int) $row['id'], (string) ($row['starts_at'] ?? ''), $row);
    }
    if (function_exists('jeab_ordrar_clear_bokad_tid_from_booking')) {
        jeab_ordrar_clear_bokad_tid_from_booking($row);
    }
    jeab_fire_webhook($store, 'booking.cancelled', jeab_booking_payload($row));
    try {
        jeab_calendar_sync_outlook($store, $row);
    } catch (Throwable $e) {
        jeab_calendar_sync_outlook_log($e);
    }
    return $row;
}

function jeab_calendar_slots(DateTimeImmutable $day): array
{
    $slots = [];
    $start = $day->setTime(7, 0);
    $limit = $day->setTime(17, 0);
    for ($t = $start; $t < $limit; $t = $t->modify('+30 minutes')) {
        $slots[] = [
            'start' => $t,
            'end' => $t->modify('+30 minutes'),
        ];
    }
    return $slots;
}

function jeab_booking_is_all_day(string $startsAt, string $endsAt): bool
{
    try {
        $start = new DateTimeImmutable($startsAt);
        $end = new DateTimeImmutable($endsAt);
    } catch (Exception) {
        return false;
    }
    return $start->format('H:i') === '00:00'
        && $end->format('H:i') === '00:00'
        && $end > $start;
}

function jeab_booking_weekday_count(string $startsAt, string $endsAt): int
{
    try {
        $start = (new DateTimeImmutable($startsAt))->setTime(0, 0);
        $end = (new DateTimeImmutable($endsAt))->setTime(0, 0);
    } catch (Exception) {
        return 0;
    }
    $n = 0;
    for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
        if ((int) $day->format('N') <= 5) {
            $n++;
        }
    }
    return $n;
}

function jeab_booking_add_weekdays(string $startsAt, int $days): string
{
    try {
        $cursor = (new DateTimeImmutable($startsAt))->setTime(0, 0);
    } catch (Exception) {
        $cursor = new DateTimeImmutable('today');
    }
    $days = max(1, min(10, $days));
    while ((int) $cursor->format('N') >= 6) {
        $cursor = $cursor->modify('+1 day');
    }
    $seen = 0;
    while ($seen < $days) {
        if ((int) $cursor->format('N') <= 5) {
            $seen++;
            if ($seen === $days) {
                return $cursor->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s');
            }
        }
        $cursor = $cursor->modify('+1 day');
    }
    return $cursor->setTime(0, 0)->format('Y-m-d H:i:s');
}

function jeab_booking_clamp_allday(string $startsAt, string $endsAt): array
{
    if (!jeab_booking_is_all_day($startsAt, $endsAt)) {
        return [$startsAt, $endsAt];
    }
    $n = jeab_booking_weekday_count($startsAt, $endsAt);
    if ($n < 1) {
        $n = 1;
    }
    if ($n > 10) {
        $n = 10;
    }
    return [$startsAt, jeab_booking_add_weekdays($startsAt, $n)];
}

function jeab_booking_allday_placement(string $startsAt, string $endsAt, DateTimeImmutable $monday): ?array
{
    if (!jeab_booking_is_all_day($startsAt, $endsAt)) {
        return null;
    }
    $tz = $monday->getTimezone();
    try {
        $start = (new DateTimeImmutable($startsAt, $tz))->setTime(0, 0);
        $end = (new DateTimeImmutable($endsAt, $tz))->setTime(0, 0);
    } catch (Exception) {
        return null;
    }
    $weekStart = $monday->setTime(0, 0);
    $weekEnd = $monday->modify('+5 days')->setTime(0, 0);
    if ($end <= $weekStart || $start >= $weekEnd) {
        return null;
    }
    if ($start < $weekStart) {
        $start = $weekStart;
    }
    if ($end > $weekEnd) {
        $end = $weekEnd;
    }
    $offset = (int) $weekStart->diff($start)->days;
    $span = (int) $start->diff($end)->days;
    if ($span < 1) {
        return null;
    }
    return ['col' => $offset + 2, 'span' => $span];
}

function jeab_booking_grid_placement(string $startsAt, string $endsAt, DateTimeImmutable $day): ?array
{
    if (jeab_booking_is_all_day($startsAt, $endsAt)) {
        return null;
    }
    $tz = $day->getTimezone();
    try {
        $start = new DateTimeImmutable($startsAt, $tz);
        $end = new DateTimeImmutable($endsAt, $tz);
    } catch (Exception) {
        return null;
    }
    $gridStart = $day->setTime(7, 0);
    $gridEnd = $day->setTime(17, 0);
    if ($end <= $gridStart || $start >= $gridEnd) {
        return null;
    }
    if ($start < $gridStart) {
        $start = $gridStart;
    }
    if ($end > $gridEnd) {
        $end = $gridEnd;
    }
    if ($end <= $start) {
        $end = $start->modify('+30 minutes');
    }
    $base = 7 * 60;
    $startMin = ((int) $start->format('H') * 60) + (int) $start->format('i') - $base;
    $endMin = ((int) $end->format('H') * 60) + (int) $end->format('i') - $base;
    $row = (int) floor($startMin / 30) + 1;
    $span = max(1, (int) ceil($endMin / 30) - (int) floor($startMin / 30));
    $last = 20;
    if ($row > $last) {
        return null;
    }
    if ($row + $span - 1 > $last) {
        $span = $last - $row + 1;
    }
    return ['row' => $row, 'span' => $span];
}

function jeab_calendar_pack_lanes(array $items): array
{
    $clean = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = (string) ($item['key'] ?? '');
        $start = (int) ($item['start'] ?? 0);
        $end = (int) ($item['end'] ?? 0);
        if ($key === '' || $end <= $start) {
            continue;
        }
        $clean[] = ['key' => $key, 'start' => $start, 'end' => $end];
    }
    if ($clean === []) {
        return [];
    }
    usort($clean, static function (array $a, array $b): int {
        if ($a['start'] !== $b['start']) {
            return $a['start'] <=> $b['start'];
        }
        return ($b['end'] - $b['start']) <=> ($a['end'] - $a['start']);
    });

    $used = [];
    foreach ($clean as $i => $item) {
        $lane = 0;
        while (true) {
            $hit = false;
            foreach ($used[$lane] ?? [] as $range) {
                if ($item['start'] < $range[1] && $range[0] < $item['end']) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                break;
            }
            $lane++;
        }
        $used[$lane][] = [$item['start'], $item['end']];
        $clean[$i]['lane'] = $lane;
    }

    $n = count($clean);
    $parent = range(0, $n - 1);
    $find = static function (int $i) use (&$parent, &$find): int {
        if ($parent[$i] !== $i) {
            $parent[$i] = $find($parent[$i]);
        }
        return $parent[$i];
    };
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if ($clean[$i]['start'] < $clean[$j]['end'] && $clean[$j]['start'] < $clean[$i]['end']) {
                $a = $find($i);
                $b = $find($j);
                if ($a !== $b) {
                    $parent[$b] = $a;
                }
            }
        }
    }
    $clusterLanes = [];
    $clusterSize = [];
    for ($i = 0; $i < $n; $i++) {
        $root = $find($i);
        $clusterLanes[$root] = max($clusterLanes[$root] ?? 0, (int) $clean[$i]['lane'] + 1);
        $clusterSize[$root] = ($clusterSize[$root] ?? 0) + 1;
    }
    $out = [];
    foreach ($clean as $i => $item) {
        $root = $find($i);
        $out[$item['key']] = [
            'key' => $item['key'],
            'start' => $item['start'],
            'end' => $item['end'],
            'lane' => (int) $item['lane'],
            'lanes' => max(1, (int) ($clusterLanes[$root] ?? 1)),
            'overlap' => ($clusterSize[$root] ?? 1) > 1,
        ];
    }
    return $out;
}

function jeab_calendar_lane_style(array $lane): string
{
    $n = max(1, (int) ($lane['lanes'] ?? 1));
    $i = max(0, (int) ($lane['lane'] ?? 0));
    return '--lane-n:' . $n . ';--lane-i:' . $i;
}

function jeab_calendar_busy_data_attrs(array $event): string
{
    $starts = '';
    $ends = '';
    try {
        if (!empty($event['starts_at'])) {
            $starts = (new DateTimeImmutable((string) $event['starts_at']))->format('Y-m-d\TH:i');
        }
        if (!empty($event['ends_at'])) {
            $ends = (new DateTimeImmutable((string) $event['ends_at']))->format('Y-m-d\TH:i');
        }
    } catch (Exception) {
        $starts = '';
        $ends = '';
    }
    $pairs = [
        'data-who' => (string) ($event['who'] ?? ''),
        'data-title' => (string) ($event['title'] ?? ''),
        'data-starts' => $starts,
        'data-ends' => $ends,
        'data-allday' => !empty($event['all_day']) ? '1' : '0',
    ];
    $html = '';
    foreach ($pairs as $name => $value) {
        $html .= ' ' . $name . '="' . e($value) . '"';
    }
    return $html;
}

/**
 * ?open= på kalendern: hämta bokningen, ställ vecka eller dag så fönstret
 * innehåller starts_at, och vid behov kontor plus who=all/job=all så raden ritas.
 *
 * @return array<string, mixed>|null
 */
function jeab_bookings_apply_open(JeabStore $store): ?array
{
    $id = max(0, (int) ($_GET['open'] ?? 0));
    if ($id <= 0) {
        return null;
    }
    $row = jeab_get_booking($store, $id);
    if (!is_array($row) || trim((string) ($row['starts_at'] ?? '')) === '') {
        return null;
    }
    $_GET['co'] = jeab_booking_company($row);
    if (!jeab_booking_matches_filter($row, jeab_booking_who_filter(), jeab_booking_job_filter())) {
        $_GET['who'] = 'all';
        $_GET['job'] = 'all';
    }
    try {
        $start = new DateTimeImmutable((string) $row['starts_at']);
    } catch (Exception) {
        return $row;
    }
    $weekday = (int) $start->format('N');
    if (jeab_booking_view() === 'day' && $weekday < 6) {
        $_GET['d'] = $start->format('Y-m-d');
    } else {
        if ($weekday >= 6) {
            unset($_GET['view']);
        }
        $_GET['w'] = (string) jeab_week_offset_for_monday(jeab_monday_of($start));
    }
    return $row;
}

function jeab_calendar_visible_window(): array
{
    $weekOffset = (int) ($_GET['w'] ?? 0);
    if (!isset($_GET['w']) && isset($_GET['monday'])) {
        try {
            $jump = new DateTimeImmutable((string) $_GET['monday'], new DateTimeZone(date_default_timezone_get()));
            $weekOffset = jeab_week_offset_for_monday(jeab_monday_of($jump));
        } catch (Exception) {
            $weekOffset = 0;
        }
    }
    $focusDay = null;
    if (jeab_booking_view() === 'day') {
        $focusDay = jeab_calendar_focus_day();
        $weekOffset = jeab_week_offset_for_monday(jeab_monday_of($focusDay));
    }
    $monday = jeab_week_monday($weekOffset);
    $monthView = jeab_booking_view() === 'month';
    $monthDate = $monday->modify('first day of this month')->setTime(0, 0);
    $monthMondays = jeab_mondays_in_month((int) $monthDate->format('Y'), (int) $monthDate->format('n'));
    if ($monthMondays === []) {
        $monthMondays = [$monday];
    }
    if ($monthView) {
        $rangeFrom = $monthMondays[0];
        $rangeTo = end($monthMondays)->modify('+5 days');
    } else {
        $rangeFrom = $monday;
        $rangeTo = $monday->modify('+5 days');
    }
    return [
        'weekOffset' => $weekOffset,
        'monday' => $monday,
        'monthView' => $monthView,
        'monthDate' => $monthDate,
        'monthMondays' => $monthMondays,
        'rangeFrom' => $rangeFrom,
        'rangeTo' => $rangeTo,
        'day' => $focusDay,
    ];
}

function jeab_calendar_wave_booking_html(array $row, array $place, array $lane, bool $allDay): string
{
    $style = $allDay
        ? 'grid-column: ' . (int) $place['col'] . ' / span ' . (int) $place['span'] . '; grid-row: ' . ((int) ($lane['lane'] ?? 0) + 1)
        : 'grid-row: ' . (int) $place['row'] . ' / span ' . (int) $place['span'] . '; ' . jeab_calendar_lane_style($lane);
    if (!$allDay) {
        $style .= '; --ord: ' . jeab_calendar_order_key((string) ($row['starts_at'] ?? ''));
    }
    $overlap = !empty($lane['overlap']) ? ' data-overlap="1"' : '';
    $extra = $allDay ? ' wg-allday-item' : '';
    return '<button class="wg-item' . $extra . ' ' . e(jeab_booking_job_class($row)) . '" type="button" data-js="edit-booking" data-wave="bookings" style="' . e($style) . '"' . $overlap . jeab_booking_data_attrs($row) . '>'
        . jeab_booking_summary_html($row)
        . '</button>';
}

function jeab_calendar_wave_outlook_html(array $event, array $place, array $lane, bool $allDay): string
{
    $style = $allDay
        ? 'grid-column: ' . (int) $place['col'] . ' / span ' . (int) $place['span'] . '; grid-row: ' . ((int) ($lane['lane'] ?? 0) + 1)
        : 'grid-row: ' . (int) $place['row'] . ' / span ' . (int) $place['span'] . '; ' . jeab_calendar_lane_style($lane);
    $overlap = !empty($lane['overlap']) ? ' data-overlap="1"' : '';
    $extra = $allDay ? ' wg-allday-item' : '';
    if (!$allDay) {
        $style .= '; --ord: ' . jeab_calendar_order_key((string) ($event['starts_at'] ?? ''));
    }
    $row = jeab_calendar_outlook_booking_row($event);
    if ($row !== null) {
        return '<button class="wg-item' . $extra . ' ' . e(jeab_booking_job_class($row)) . '" type="button" data-js="edit-booking" data-wave="bookings" style="' . e($style) . '"' . $overlap . jeab_booking_data_attrs($row) . '>'
            . jeab_calendar_outlook_inner_html($event, $allDay)
            . '</button>';
    }
    return '<div class="wg-item' . $extra . ' is-outlook is-internal" data-js="outlook-busy" data-wave="outlook" style="' . e($style) . '"' . $overlap . jeab_calendar_busy_data_attrs($event) . '>'
        . jeab_calendar_outlook_inner_html($event, $allDay)
        . '</div>';
}

function jeab_calendar_wave_month_html(array $item): string
{
    if (($item['kind'] ?? '') === 'outlook') {
        $event = is_array($item['event'] ?? null) ? $item['event'] : [];
        $row = jeab_calendar_outlook_booking_row($event);
        if ($row !== null) {
            return '<button class="mv-item ' . e(jeab_booking_job_class($row)) . '" type="button" data-js="edit-booking" data-wave="bookings"' . jeab_booking_data_attrs($row) . '>'
                . e(jeab_month_outlook_label($event, !empty($item['allday'])))
                . '</button>';
        }
        return '<div class="mv-item is-outlook" data-js="outlook-busy" data-wave="outlook"' . jeab_calendar_busy_data_attrs($event) . '>'
            . e(jeab_month_outlook_label($event, !empty($item['allday'])))
            . '</div>';
    }
    return '<button class="mv-item ' . e(jeab_booking_job_class($item['row'])) . '" type="button" data-js="edit-booking" data-wave="bookings"' . jeab_booking_data_attrs($item['row']) . '>'
        . e(jeab_month_booking_label($item['row'], !empty($item['allday'])))
        . '</button>';
}

function jeab_calendar_wave_payload(array $bookings, array $outlookEvents, DateTimeImmutable $monday, bool $monthView, ?array $officeBookings = null): array
{
    if ($officeBookings !== null) {
        $prepared = jeab_calendar_prepare_visible($bookings, $officeBookings, $outlookEvents);
        $bookings = $prepared['bookings'];
        $outlookEvents = $prepared['outlook'];
    } else {
        $outlookEvents = jeab_calendar_merge_outlook(jeab_calendar_drop_duplicate_outlook($outlookEvents, $bookings));
    }
    $days = [];
    for ($i = 0; $i < 5; $i++) {
        $days[] = $monday->modify('+' . $i . ' days');
    }
    $outside = $monthView
        ? ['early' => [], 'late' => []]
        : jeab_calendar_outside_hours($bookings, $outlookEvents, $days);
    $timed = [];
    $allday = [];
    $month = [];
    $sols = [];
    if (!$monthView) {
        foreach ($days as $day) {
            $timed[$day->format('Y-m-d')] = [];
        }
    }

    $allDayRows = [];
    $outlookAllDay = [];
    $byDay = [];
    $outlookByDay = [];
    if (!$monthView) {
    foreach ($bookings as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (jeab_booking_is_all_day((string) $row['starts_at'], (string) $row['ends_at'])) {
            $place = jeab_booking_allday_placement((string) $row['starts_at'], (string) $row['ends_at'], $monday);
            if ($place) {
                $allDayRows[] = ['row' => $row, 'place' => $place];
            }
            continue;
        }
        $key = (new DateTimeImmutable($row['starts_at']))->format('Y-m-d');
        $byDay[$key][] = $row;
    }
    foreach ($outlookEvents as $event) {
        if (!is_array($event)) {
            continue;
        }
        if (!empty($event['all_day'])) {
            $place = jeab_booking_allday_placement((string) $event['starts_at'], (string) $event['ends_at'], $monday);
            if ($place) {
                $outlookAllDay[] = ['event' => $event, 'place' => $place];
            }
            continue;
        }
        foreach ($days as $day) {
            $place = jeab_booking_grid_placement((string) $event['starts_at'], (string) $event['ends_at'], $day);
            if ($place) {
                $outlookByDay[$day->format('Y-m-d')][] = ['event' => $event, 'place' => $place];
            }
        }
    }

    $allDayPack = [];
    foreach ($allDayRows as $idx => $item) {
        $allDayPack[] = ['key' => 'b' . $idx, 'start' => (int) $item['place']['col'], 'end' => (int) $item['place']['col'] + (int) $item['place']['span']];
    }
    foreach ($outlookAllDay as $idx => $item) {
        $allDayPack[] = ['key' => 'o' . $idx, 'start' => (int) $item['place']['col'], 'end' => (int) $item['place']['col'] + (int) $item['place']['span']];
    }
    $allDayLanes = jeab_calendar_pack_lanes($allDayPack);
    $alldayEntries = [];
    foreach ($outlookAllDay as $idx => $item) {
        $alldayEntries[] = ['kind' => 'outlook', 'key' => 'o' . $idx, 'event' => $item['event'], 'place' => $item['place']];
    }
    foreach ($allDayRows as $idx => $item) {
        $alldayEntries[] = ['kind' => 'booking', 'key' => 'b' . $idx, 'row' => $item['row'], 'place' => $item['place']];
    }
    foreach (jeab_calendar_paint_order($alldayEntries) as $entry) {
        $lane = $allDayLanes[$entry['key']] ?? ['lane' => 0, 'overlap' => false];
        if (($entry['kind'] ?? '') === 'outlook') {
            $allday[] = jeab_calendar_wave_outlook_html($entry['event'], $entry['place'], $lane, true);
        } else {
            $allday[] = jeab_calendar_wave_booking_html($entry['row'], $entry['place'], $lane, true);
        }
    }

    foreach ($days as $day) {
        $key = $day->format('Y-m-d');
        $list = [];
        $pack = [];
        foreach ($byDay[$key] ?? [] as $idx => $row) {
            $place = jeab_booking_grid_placement((string) $row['starts_at'], (string) $row['ends_at'], $day);
            if (!$place) {
                continue;
            }
            $id = 'b' . $idx;
            $list[] = ['kind' => 'booking', 'key' => $id, 'row' => $row, 'place' => $place];
            $pack[] = ['key' => $id, 'start' => (int) $place['row'], 'end' => (int) $place['row'] + (int) $place['span']];
        }
        foreach ($outlookByDay[$key] ?? [] as $idx => $item) {
            $id = 'o' . $idx;
            $list[] = ['kind' => 'outlook', 'key' => $id, 'event' => $item['event'], 'place' => $item['place']];
            $pack[] = ['key' => $id, 'start' => (int) $item['place']['row'], 'end' => (int) $item['place']['row'] + (int) $item['place']['span']];
        }
        $lanes = jeab_calendar_pack_lanes($pack);
        foreach (jeab_calendar_paint_order($list) as $entry) {
            $lane = $lanes[$entry['key']] ?? ['lane' => 0, 'lanes' => 1, 'overlap' => false];
            if (($entry['kind'] ?? '') === 'outlook') {
                $timed[$key][] = jeab_calendar_wave_outlook_html($entry['event'], $entry['place'], $lane, false);
            } else {
                $timed[$key][] = jeab_calendar_wave_booking_html($entry['row'], $entry['place'], $lane, false);
            }
        }
    }
    }

    if ($monthView) {
        $monthDate = $monday->modify('first day of this month')->setTime(0, 0);
        $monthMondays = jeab_mondays_in_month((int) $monthDate->format('Y'), (int) $monthDate->format('n'));
        if ($monthMondays === []) {
            $monthMondays = [$monday];
        }
        foreach ($monthMondays as $weekMonday) {
            $solBars = jeab_month_sol_bars($bookings, $weekMonday);
            $solHtml = [];
            foreach ($solBars as $bar) {
                $solHtml[] = '<button class="mv-sol is-sol" type="button" data-js="edit-booking" data-wave="bookings" style="grid-column: ' . (int) $bar['col'] . ' / span ' . (int) $bar['span'] . '"' . jeab_booking_data_attrs($bar['row']) . '>'
                    . e(jeab_month_sol_label($bar['row']))
                    . '</button>';
            }
            $sols[$weekMonday->format('Y-m-d')] = $solHtml;
            for ($d = 0; $d < 5; $d++) {
                $day = $weekMonday->modify('+' . $d . ' days');
                $items = [];
                foreach (jeab_calendar_paint_order(jeab_month_day_items($bookings, $outlookEvents, $day)) as $item) {
                    $items[] = jeab_calendar_wave_month_html($item);
                }
                $month[$day->format('Y-m-d')] = $items;
            }
        }
    }

    return [
        'timed' => $timed,
        'allday' => $allday,
        'allday_rows' => count($allday) + 1,
        'month' => $month,
        'sols' => $sols,
        'early' => $outside['early'],
        'late' => $outside['late'],
    ];
}

function jeab_calendar_office_bookings(JeabStore $store, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $fromSql = $from->format('Y-m-d H:i:s');
    $toSql = $to->format('Y-m-d H:i:s');
    $company = jeab_selected_company();
    if ($store instanceof JeabPdoStore && function_exists('jeab_booking_query_mode') && !empty(jeab_booking_query_mode($store->pdo())['company'])) {
        $rows = $store->bookingsBetween($fromSql, $toSql, $company);
    } else {
        $rows = jeab_bookings_between($store, $fromSql, $toSql);
    }

    return jeab_filter_bookings_for_company($rows, $company);
}

/**
 * Kontorets bokningar en gång. Who/job filtreras i PHP på samma lista.
 *
 * @return array{rows:list<array<string,mixed>>,office:list<array<string,mixed>>}
 */
function jeab_calendar_loaded_bookings(JeabStore $store, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $office = jeab_calendar_office_bookings($store, $from, $to);

    return [
        'office' => $office,
        'rows' => jeab_filter_bookings($office, jeab_booking_who_filter(), jeab_booking_job_filter()),
    ];
}

function jeab_calendar_filtered_bookings(JeabStore $store, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $rows = jeab_calendar_office_bookings($store, $from, $to);
    return jeab_filter_bookings($rows, jeab_booking_who_filter(), jeab_booking_job_filter());
}

function jeab_calendar_wave_release(): void
{
    if (function_exists('jeab_release_session')) {
        jeab_release_session();

        return;
    }
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function jeab_calendar_respond_wave(JeabStore $store, string $intent): never
{
    $win = jeab_calendar_visible_window();
    $monday = $win['monday'];
    $monthView = $win['monthView'];
    $who = jeab_booking_who_filter();
    $job = jeab_booking_job_filter();
    jeab_calendar_wave_release();
    if ($intent === 'calendar-bookings') {
        $waveStarted = microtime(true);
        $loaded = jeab_calendar_loaded_bookings($store, $win['rangeFrom'], $win['rangeTo']);
        jeab_calendar_warm_contacts($loaded['rows']);
        if (function_exists('jeab_timing_add')) {
            jeab_timing_add('calendar_bookings', microtime(true) - $waveStarted);
        }
        jeab_json(['ok' => true, 'wave' => 'bookings'] + jeab_calendar_wave_payload($loaded['rows'], [], $monday, $monthView));
    }
    $outlook = [];
    $stale = false;
    if (jeab_calendar_include_outlook($job)) {
        try {
            $read = function_exists('jeab_gp_week_events_cache_read')
                ? jeab_gp_week_events_cache_read($monthView ? $win['rangeFrom'] : $monday, $who, $monthView ? $win['rangeTo'] : null)
                : ['events' => [], 'stale' => true];
            $outlook = is_array($read['events'] ?? null) ? $read['events'] : [];
            $stale = !empty($read['stale']);
        } catch (Throwable $e) {
            error_log('calendar outlook wave ' . $e::class);
            $outlook = [];
            $stale = true;
        }
    }
    $loaded = jeab_calendar_loaded_bookings($store, $win['rangeFrom'], $win['rangeTo']);
    jeab_calendar_warm_contacts(array_merge($loaded['rows'], $loaded['office']));
    jeab_json(['ok' => true, 'wave' => 'outlook', 'stale' => $stale, 'stale_label' => $stale ? 'uppdateras…' : ''] + jeab_calendar_wave_payload($loaded['rows'], $outlook, $monday, $monthView, $loaded['office']));
}

/** @param list<array<string, mixed>> $rows */
function jeab_calendar_warm_contacts(array $rows): void
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

function jeab_booking_data_attrs(array $row): string
{
    $row = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($row) : $row;
    $starts = (new DateTimeImmutable($row['starts_at']))->format('Y-m-d\TH:i');
    $pairs = [
        'data-id' => (string) (int) $row['id'],
        'data-editable' => '1',
        'data-customer' => (string) $row['customer_name'],
        'data-email' => (string) ($row['email'] ?? ''),
        'data-phone' => jeab_normalize_booking_phone($row['phone'] ?? null) ?? '',
        'data-address' => (string) ($row['address'] ?? ''),
        // Sparad kalenderanteckning. Inte omkomponerad från order/påminnelse,
        // annars ser Spara ut att misslyckas när bakgrunden har install_note.
        'data-notes' => trim((string) ($row['notes'] ?? '')),
        'data-assignees' => jeab_assignees_join(jeab_assignees_from_row($row)),
        'data-job' => (string) ($row['job_type'] ?? ''),
        'data-starts' => $starts,
        'data-ends' => (new DateTimeImmutable($row['ends_at']))->format('Y-m-d\TH:i'),
        'data-allday' => jeab_booking_is_all_day((string) $row['starts_at'], (string) $row['ends_at']) ? '1' : '0',
        'data-when' => jeab_calendar_when_label((string) $row['starts_at'], (string) $row['ends_at']),
    ];
    $links = jeab_calendar_booking_links($row);
    if ($links['order'] !== '') {
        $pairs['data-order'] = $links['order'];
    }
    if ($links['reminder'] !== '') {
        $pairs['data-reminder'] = $links['reminder'];
    }
    $html = '';
    foreach ($pairs as $name => $value) {
        $html .= ' ' . $name . '="' . e($value) . '"';
    }
    return $html;
}

function jeab_booking_street(?string $address): string
{
    $address = trim((string) $address);
    if ($address === '') {
        return '';
    }
    if (function_exists('jeab_reminder_street')) {
        return jeab_reminder_street($address);
    }
    $address = trim((string) preg_replace('/\b\d{3}\s?\d{2}\b/u', ' ', $address));
    $address = trim((string) preg_replace('/\s+/u', ' ', $address), " \t\n\r\0\x0B,");
    if (str_contains($address, ',')) {
        return trim((string) explode(',', $address, 2)[0]);
    }
    return $address;
}

function jeab_booking_city(?string $address): string
{
    $address = trim((string) $address);
    if ($address === '') {
        return '';
    }
    if (str_contains($address, ',')) {
        $parts = array_map('trim', explode(',', $address));
        $last = (string) end($parts);
    } else {
        $last = $address;
    }
    $last = trim((string) preg_replace('/^\d{3}\s?\d{2}\s+/u', '', $last));
    if ($last === '') {
        return '';
    }
    if (preg_match('/\b(\p{Lu}[\p{L}-]+(?:\s+\p{Lu}[\p{L}-]+)?)$/u', $last, $m)) {
        return $m[1];
    }
    return $last;
}

function jeab_booking_block_label(array $row): string
{
    $row = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($row) : $row;
    $job = jeab_job_types_label($row);
    $city = jeab_booking_city($row['address'] ?? null);
    $label = trim($job . ($city !== '' ? ' ' . $city : ''));
    return $label !== '' ? $label : 'Bokning';
}

function jeab_booking_job_class(array $row): string
{
    $jobs = jeab_job_types_from_row($row);
    if ($jobs === []) {
        return 'is-none';
    }
    return implode(' ', array_map(static fn(string $job): string => 'is-' . $job, $jobs));
}

function jeab_booking_address_lead(?string $address): string
{
    $address = trim((string) $address);
    if ($address === '') {
        return '';
    }
    $street = jeab_booking_street($address);
    $city = jeab_booking_city($address);
    $post = '';
    if (preg_match('/\b(\d{3})\s?(\d{2})\b/u', $address, $m)) {
        $post = $m[1] . ' ' . $m[2];
    }
    $tail = trim($post . ($city !== '' ? ' ' . $city : ''));
    $streetKey = mb_strtolower($street);
    $cityKey = mb_strtolower($city);
    $tailKey = mb_strtolower($tail);
    // En rad; CSS radbryter bara om rutan är för smal.
    if ($street !== '' && $tail !== '' && $streetKey !== $tailKey && $streetKey !== $cityKey) {
        return $street . ', ' . $tail;
    }
    if ($street !== '') {
        return $street;
    }
    $parts = array_values(array_filter(array_map('trim', explode(',', $address)), static fn(string $part): bool => $part !== ''));
    return $parts !== [] ? implode(', ', $parts) : $address;
}

function jeab_booking_summary_meta(array $row): string
{
    $row = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($row) : $row;
    $job = jeab_job_types_label($row);
    $parts = [
        $job,
        trim((string) ($row['customer_name'] ?? '')),
    ];
    $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    return $parts !== [] ? implode(' · ', $parts) : '';
}

function jeab_booking_summary_lead(array $row): string
{
    $row = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($row) : $row;
    $address = jeab_booking_address_lead($row['address'] ?? null);
    if ($address !== '') {
        return $address;
    }
    $meta = jeab_booking_summary_meta($row);
    return $meta !== '' ? $meta : 'Bokning';
}

function jeab_booking_summary_html(array $row): string
{
    $row = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($row) : $row;
    $address = jeab_booking_address_lead($row['address'] ?? null);
    $customer = trim((string) ($row['customer_name'] ?? ''));
    $allDay = jeab_booking_is_all_day((string) ($row['starts_at'] ?? ''), (string) ($row['ends_at'] ?? ''));
    $time = $allDay ? '' : jeab_calendar_time_label((string) ($row['starts_at'] ?? ''), (string) ($row['ends_at'] ?? ''));
    $html = $time !== '' ? '<span class="wg-time">' . e($time) . '</span>' : '';
    if ($allDay) {
        // Mobillistan visar vilka dagar en heldagsbokning gäller (i rutnätet syns det av bredden).
        $html .= '<span class="wg-time wg-days">' . e(jeab_calendar_when_label((string) ($row['starts_at'] ?? ''), (string) ($row['ends_at'] ?? ''))) . '</span>';
    }
    if ($address !== '') {
        $html .= '<strong class="wg-lead">' . e($address) . '</strong>';
        if ($customer !== '') {
            $html .= '<span class="wg-meta">' . e($customer) . '</span>';
        }
        return $html;
    }
    if ($customer !== '') {
        return $html . '<strong class="wg-lead">' . e($customer) . '</strong>';
    }
    $job = jeab_job_types_label($row);
    return $html . '<strong class="wg-lead">' . e($job !== '' ? $job : 'Bokning') . '</strong>';
}

function jeab_booking_title(array $row): string
{
    $row = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($row) : $row;
    $job = jeab_job_types_label($row);
    $parts = array_filter([$row['customer_name'], $job, jeab_assignees_join(jeab_assignees_from_row($row))]);
    return implode(' · ', $parts);
}

function jeab_calendar_log_path(): string
{
    if (!empty($GLOBALS['_JEAB_CALENDAR_LOG_PATH'])) {
        return (string) $GLOBALS['_JEAB_CALENDAR_LOG_PATH'];
    }
    $dir = dirname(jeab_json_path());
    if (str_replace('\\', '/', $dir) === str_replace('\\', '/', jeab_data_dir())) {
        return jeab_data_file('calendar-log.json');
    }
    return $dir . '/calendar-log.json';
}

function jeab_calendar_log_labels(): array
{
    return [
        'booked' => 'Bokad',
        'cancelled' => 'Avbokad',
        'rescheduled' => 'Ombokad',
        'sms' => 'Skickade sms',
        'mail' => 'Skickade mail',
    ];
}

function jeab_calendar_log_label(string $type): string
{
    return jeab_calendar_log_labels()[$type] ?? '';
}

function jeab_calendar_log_empty(): array
{
    return [
        'entries' => [],
        'backfilled' => false,
    ];
}

function jeab_calendar_log_normalize(array $data): array
{
    $entries = $data['entries'] ?? $data;
    if (!is_array($entries)) {
        $entries = [];
    }
    $clean = [];
    foreach ($entries as $entry) {
        if (is_array($entry)) {
            $clean[] = $entry;
        }
    }
    return [
        'entries' => $clean,
        'backfilled' => !empty($data['backfilled']),
    ];
}

function jeab_calendar_log_file_mode(): bool
{
    if (!empty($GLOBALS['_JEAB_CALENDAR_LOG_PATH'])) {
        return true;
    }

    return function_exists('jeab_json_sql_rollback') && jeab_json_sql_rollback('calendar_log_json');
}

function jeab_calendar_log_read(): array
{
    if (!jeab_calendar_log_file_mode()) {
        if (function_exists('jeab_log_doc_get')) {
            $data = jeab_log_doc_get('calendar_log');
            if (is_array($data)) {
                return jeab_calendar_log_merge_rows(jeab_calendar_log_normalize($data));
            }
        }
        $pdo = function_exists('jeab_sql_store_pdo') ? jeab_sql_store_pdo() : null;
        if ($pdo instanceof PDO && function_exists('jeab_sql_doc_get')) {
            $data = jeab_sql_doc_get($pdo, 'calendar_log');
            if (is_array($data)) {
                return jeab_calendar_log_merge_rows(jeab_calendar_log_normalize($data));
            }
        }
        $hasSql = ($pdo instanceof PDO) || (function_exists('jeab_log_pdo_raw') && jeab_log_pdo_raw() instanceof PDO);
        if ($hasSql || (function_exists('jeab_data_type_forced') && jeab_data_type_forced('sql', 'calendar_log'))) {
            return jeab_calendar_log_merge_rows(jeab_calendar_log_empty());
        }
    }
    $path = jeab_calendar_log_path();
    if (!is_file($path) || !is_readable($path)) {
        return jeab_calendar_log_merge_rows(jeab_calendar_log_empty());
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return jeab_calendar_log_merge_rows(jeab_calendar_log_empty());
    }
    return jeab_calendar_log_merge_rows(jeab_calendar_log_normalize($data));
}

/**
 * Dokumentrader först, sedan händelserader. Samma id: händelseraden vinner.
 *
 * @param list<array<string, mixed>> $docEntries
 * @param list<array<string, mixed>> $lineEntries
 * @return list<array<string, mixed>>
 */
function jeab_calendar_log_merge_lists(array $docEntries, array $lineEntries): array
{
    $loose = [];
    $byId = [];
    $take = static function (array $entry) use (&$loose, &$byId): void {
        $id = (int) ($entry['id'] ?? 0);
        if ($id <= 0) {
            $loose[] = $entry;
            return;
        }
        $byId[$id] = $entry;
    };
    foreach ($docEntries as $entry) {
        if (is_array($entry)) {
            $take($entry);
        }
    }
    foreach ($lineEntries as $entry) {
        if (is_array($entry)) {
            $take($entry);
        }
    }
    ksort($byId);
    return array_merge($loose, array_values($byId));
}

/** En loggrad per händelse. Dokumentet är kvar för äldre rader och för en hel omskrivning. */
function jeab_calendar_log_merge_rows(array $data): array
{
    if (!empty($GLOBALS['_JEAB_CALENDAR_LOG_PATH']) || !function_exists('jeab_log_lines_decoded')) {
        return $data;
    }
    $extra = jeab_log_lines_decoded('calendar_event');
    if (!is_array($extra) || $extra === []) {
        return $data;
    }
    $data['entries'] = jeab_calendar_log_merge_lists($data['entries'], $extra);
    return $data;
}

function jeab_calendar_log_store_row(array $entry): bool
{
    if (!empty($GLOBALS['_JEAB_CALENDAR_LOG_PATH']) || !function_exists('jeab_log_line_append_raw')) {
        return false;
    }
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') {
        return false;
    }
    return jeab_log_line_append_raw('calendar_event', $line);
}

/** Tar bort utpekade händelserader. En omskrivning av dokumentet rör inte övriga rader. */
function jeab_calendar_log_rows_drop_ids(array $ids): void
{
    if (!empty($GLOBALS['_JEAB_CALENDAR_LOG_PATH']) || !function_exists('jeab_log_pdo')) {
        return;
    }
    $want = [];
    foreach ($ids as $id) {
        if ((int) $id > 0) {
            $want[(int) $id] = true;
        }
    }
    if ($want === []) {
        return;
    }
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO) {
            return;
        }
        $stmt = $pdo->prepare('SELECT id, payload FROM log_lines WHERE kind = ?');
        $stmt->execute(['calendar_event']);
        $drop = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string) ($row['payload'] ?? ''), true);
            $eventId = is_array($decoded) ? (int) ($decoded['id'] ?? 0) : 0;
            if ($eventId > 0 && !empty($want[$eventId])) {
                $drop[] = (int) $row['id'];
            }
        }
        if ($drop === []) {
            return;
        }
        $marks = implode(',', array_fill(0, count($drop), '?'));
        $pdo->prepare('DELETE FROM log_lines WHERE id IN (' . $marks . ')')->execute($drop);
    } catch (Throwable) {
        // Filvägen och dokumentet gäller om radtabellen saknas.
    }
}

/** Hel rensning. write anropar den inte: en gammal dokumentbild skulle annars ta bort dagens rader. */
function jeab_calendar_log_rows_clear(): void
{
    if (!empty($GLOBALS['_JEAB_CALENDAR_LOG_PATH']) || !function_exists('jeab_log_pdo')) {
        return;
    }
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO) {
            return;
        }
        $pdo->prepare('DELETE FROM log_lines WHERE kind = ?')->execute(['calendar_event']);
    } catch (Throwable) {
        // Filvägen och dokumentet gäller om radtabellen saknas.
    }
}

function jeab_calendar_log_write(array $data): void
{
    $payload = [
        'entries' => array_values($data['entries'] ?? []),
        'backfilled' => !empty($data['backfilled']),
    ];
    if (!jeab_calendar_log_file_mode()) {
        if (function_exists('jeab_log_calendar_save') && jeab_log_calendar_save($payload)) {
            return;
        }
        $pdo = function_exists('jeab_sql_store_pdo') ? jeab_sql_store_pdo() : null;
        if ($pdo instanceof PDO && function_exists('jeab_sql_doc_put')) {
            jeab_sql_doc_put($pdo, 'calendar_log', $payload);
            return;
        }
        $hasSql = ($pdo instanceof PDO) || (function_exists('jeab_log_pdo_raw') && jeab_log_pdo_raw() instanceof PDO);
        if ($hasSql) {
            return;
        }
    }
    $path = jeab_calendar_log_path();
    jeab_data_dir_ready($path);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return;
    }
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return;
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            return;
        }
        rewind($fp);
        if (ftruncate($fp, 0) === false || fwrite($fp, $json) === false) {
            return;
        }
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function jeab_calendar_log_source_label(?string $source): string
{
    return match ($source) {
        'web' => 'Webben',
        'outlook' => 'Outlook',
        default => '',
    };
}

function jeab_calendar_log_meta(?array $meta): array
{
    $out = [];
    if (!is_array($meta)) {
        return $out;
    }
    foreach (['grant_email', 'grant_id', 'calendar_id', 'who', 'prev_starts_at', 'prev_ends_at'] as $key) {
        $val = trim((string) ($meta[$key] ?? ''));
        if ($val !== '') {
            $out[$key] = $val;
        }
    }
    return $out;
}

function jeab_calendar_log_append(string $type, array $row, ?string $at = null, ?string $source = null, ?array $meta = null): void
{
    try {
        if (jeab_calendar_log_label($type) === '') {
            return;
        }
        $when = jeab_parse_datetime($at) ?? ($at === null ? jeab_now() : null);
        if ($when === null) {
            return;
        }
        $starts = jeab_parse_datetime(isset($row['starts_at']) ? (string) $row['starts_at'] : null);
        if ($starts === null) {
            return;
        }
        $data = jeab_calendar_log_read();
        $next = 1;
        foreach ($data['entries'] as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            if ($id >= $next) {
                $next = $id + 1;
            }
        }
        $entry = [
            'id' => $next,
            'type' => $type,
            'at' => $when,
            'booking_id' => (int) ($row['id'] ?? 0),
            'starts_at' => $starts,
            'title' => jeab_booking_title($row),
        ];
        $ends = jeab_parse_datetime(isset($row['ends_at']) ? (string) $row['ends_at'] : null);
        if ($ends !== null) {
            $entry['ends_at'] = $ends;
        }
        if ($source === 'web' || $source === 'outlook') {
            $entry['source'] = $source;
        }
        foreach (jeab_calendar_log_meta($meta) as $key => $val) {
            $entry[$key] = $val;
        }
        foreach (['prev_starts_at', 'prev_ends_at'] as $key) {
            if (!isset($entry[$key])) {
                continue;
            }
            $parsed = jeab_parse_datetime((string) $entry[$key]);
            if ($parsed === null) {
                unset($entry[$key]);
            } else {
                $entry[$key] = $parsed;
            }
        }
        if (jeab_calendar_log_store_row($entry)) {
            return;
        }
        $data['entries'][] = $entry;
        jeab_calendar_log_write($data);
    } catch (Throwable) {
        // Loggen får inte stoppa bokningen.
    }
}

/**
 * [start, end) för överlapp. Saknas slut behandlas tiden som en punkt.
 *
 * @return array{0:string,1:string}|null
 */
function jeab_calendar_log_interval(?string $startRaw, ?string $endRaw): ?array
{
    $start = jeab_parse_datetime($startRaw);
    if ($start === null) {
        return null;
    }
    $end = jeab_parse_datetime($endRaw);
    if ($end === null || $end <= $start) {
        try {
            $end = (new DateTimeImmutable($start))->modify('+1 second')->format('Y-m-d H:i:s');
        } catch (Exception) {
            return null;
        }
    }
    return [$start, $end];
}

/**
 * @return list<array{0:string,1:string}>
 */
function jeab_calendar_log_intervals(array $entry): array
{
    $out = [];
    $main = jeab_calendar_log_interval(
        isset($entry['starts_at']) ? (string) $entry['starts_at'] : null,
        isset($entry['ends_at']) ? (string) $entry['ends_at'] : null
    );
    if ($main !== null) {
        $out[] = $main;
    }
    if (trim((string) ($entry['prev_starts_at'] ?? '')) !== '') {
        $prev = jeab_calendar_log_interval(
            (string) $entry['prev_starts_at'],
            isset($entry['prev_ends_at']) ? (string) $entry['prev_ends_at'] : null
        );
        if ($prev !== null) {
            $out[] = $prev;
        }
    }
    return $out;
}

function jeab_calendar_log_with_booking_end(array $entry, ?array $booking): array
{
    if (trim((string) ($entry['ends_at'] ?? '')) !== '' || !is_array($booking)) {
        return $entry;
    }
    $entryStart = jeab_parse_datetime(isset($entry['starts_at']) ? (string) $entry['starts_at'] : null);
    $bookStart = jeab_parse_datetime(isset($booking['starts_at']) ? (string) $booking['starts_at'] : null);
    $bookEnd = jeab_parse_datetime(isset($booking['ends_at']) ? (string) $booking['ends_at'] : null);
    if ($entryStart === null || $bookStart === null || $bookEnd === null || $entryStart !== $bookStart) {
        return $entry;
    }
    $entry['ends_at'] = $bookEnd;
    return $entry;
}

/**
 * Besökstider som loggen själv känner till per bokning (start => slut).
 * Används när en rad saknar start eller slut.
 *
 * @param list<array<string, mixed>> $entries
 * @return array<int, list<array{0:string,1:?string}>>
 */
function jeab_calendar_log_history(array $entries): array
{
    $out = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id = (int) ($entry['booking_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        foreach ([['starts_at', 'ends_at'], ['prev_starts_at', 'prev_ends_at']] as [$startKey, $endKey]) {
            $start = jeab_parse_datetime(isset($entry[$startKey]) ? (string) $entry[$startKey] : null);
            if ($start === null) {
                continue;
            }
            $end = jeab_parse_datetime(isset($entry[$endKey]) ? (string) $entry[$endKey] : null);
            $out[$id][] = [$start, $end];
        }
    }
    return $out;
}

/**
 * Raden ska bära bokningens egna tider. Saknas start tas den från bokningen
 * (nuvarande tid) eller från loggens tidigare rader för samma bokning.
 * Saknas slut fylls det från bokningen eller en loggrad med samma start.
 * Går inget att hitta blir raden utan tider och visas inte i någon vecka.
 *
 * @param array<int, list<array{0:string,1:?string}>> $history
 */
function jeab_calendar_log_resolve_dates(array $entry, ?array $booking, array $history): array
{
    $id = (int) ($entry['booking_id'] ?? 0);
    $known = $id > 0 ? ($history[$id] ?? []) : [];
    $start = jeab_parse_datetime(isset($entry['starts_at']) ? (string) $entry['starts_at'] : null);
    if ($start === null) {
        unset($entry['starts_at'], $entry['ends_at']);
        $bookStart = is_array($booking)
            ? jeab_parse_datetime(isset($booking['starts_at']) ? (string) $booking['starts_at'] : null)
            : null;
        if ($bookStart !== null) {
            $entry['starts_at'] = $bookStart;
            $bookEnd = jeab_parse_datetime(isset($booking['ends_at']) ? (string) $booking['ends_at'] : null);
            if ($bookEnd !== null) {
                $entry['ends_at'] = $bookEnd;
            }
        } elseif ($known !== []) {
            [$histStart, $histEnd] = $known[count($known) - 1];
            $entry['starts_at'] = $histStart;
            if ($histEnd !== null) {
                $entry['ends_at'] = $histEnd;
            }
        } else {
            return $entry;
        }
    }
    $entry = jeab_calendar_log_with_booking_end($entry, $booking);
    if (trim((string) ($entry['ends_at'] ?? '')) === '') {
        $start = jeab_parse_datetime((string) $entry['starts_at']);
        foreach ($known as [$histStart, $histEnd]) {
            if ($histStart === $start && $histEnd !== null && $histEnd > $histStart) {
                $entry['ends_at'] = $histEnd;
                break;
            }
        }
    }
    return $entry;
}

/**
 * En vecka visar bara rader för bokningar som ligger, låg eller flyttades
 * in i eller ut ur just den veckan. Det avgörs av bokningens egna tider
 * (start–slut, och förra tiden vid ombokning), aldrig av när händelsen skrevs.
 *
 * Tidigare följde bokad/avbokad/ombokad med till veckan raden skrevs. Då syntes
 * t.ex. «2026-10-04 Bokad … · Sol» i v41 fast besöket låg i november.
 */
function jeab_calendar_log_in_week(array $entry, string $from, string $to): bool
{
    foreach (jeab_calendar_log_intervals($entry) as [$start, $end]) {
        if ($start < $to && $end > $from) {
            return true;
        }
    }
    return false;
}

function jeab_calendar_log_move_mail_sent(array $done): bool
{
    return !empty($done['move_email'])
        || !empty($done['move_mail'])
        || trim((string) ($done['move_email_at'] ?? '')) !== '';
}

/**
 * @param list<array<string, mixed>> $entries
 * @return list<array<string, mixed>>
 */
function jeab_calendar_log_channel_status(array $entries, array $sent): array
{
    foreach ($entries as $i => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id = (int) ($entry['booking_id'] ?? 0);
        $done = $sent[(string) $id] ?? null;
        if (!is_array($done)) {
            continue;
        }
        $type = (string) ($entry['type'] ?? '');
        if ($type === 'booked') {
            $entries[$i]['confirm_mail'] = !empty($done['email']) ? 'sent' : 'unsent';
        } elseif ($type === 'rescheduled') {
            $entries[$i]['move_mail'] = jeab_calendar_log_move_mail_sent($done) ? 'sent' : 'unsent';
        } elseif ($type === 'mail' && trim((string) ($entry['mail_role'] ?? '')) === '') {
            $at = trim((string) ($entry['at'] ?? ''));
            $moveAt = trim((string) ($done['move_email_at'] ?? ''));
            $emailAt = trim((string) ($done['email_at'] ?? ''));
            if ($moveAt !== '' && $at === $moveAt) {
                $entries[$i]['mail_role'] = 'move';
            } elseif ($emailAt !== '' && $at === $emailAt && !empty($done['email'])) {
                $entries[$i]['mail_role'] = 'confirm';
            }
        }
    }
    return $entries;
}

/**
 * @param list<array<string, mixed>> $already
 * @return list<array<string, mixed>>
 */
function jeab_calendar_log_sent_lines(JeabStore $store, string $from, string $to, array $already, array $sent, ?string $company = null): array
{
    $haveSms = [];
    $haveMail = [];
    $haveMove = [];
    foreach ($already as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id = (int) ($entry['booking_id'] ?? 0);
        $type = (string) ($entry['type'] ?? '');
        if ($type === 'sms') {
            $haveSms[$id] = true;
        }
        if ($type === 'mail') {
            if ((string) ($entry['mail_role'] ?? '') === 'move') {
                $haveMove[$id] = true;
            } else {
                $haveMail[$id] = true;
            }
        }
    }
    $ids = [];
    foreach ($sent as $idKey => $done) {
        if (!is_array($done)) {
            continue;
        }
        $id = (int) $idKey;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $rows = jeab_bookings_by_ids($store, array_values($ids));
    $extra = [];
    foreach ($sent as $idKey => $done) {
        if (!is_array($done)) {
            continue;
        }
        $id = (int) $idKey;
        if ($id <= 0) {
            continue;
        }
        $row = $rows[$id] ?? null;
        if (!$row || !jeab_calendar_log_company_ok($row, $company)) {
            continue;
        }
        $interval = jeab_calendar_log_interval(
            isset($row['starts_at']) ? (string) $row['starts_at'] : null,
            isset($row['ends_at']) ? (string) $row['ends_at'] : null
        );
        if ($interval === null || $interval[0] >= $to || $interval[1] <= $from) {
            continue;
        }
        $ends = jeab_parse_datetime(isset($row['ends_at']) ? (string) $row['ends_at'] : null);
        $base = [
            'booking_id' => $id,
            'starts_at' => $interval[0],
            'ends_at' => $ends ?? $interval[1],
            'title' => jeab_booking_title($row),
            'id' => 0,
        ];
        if (!empty($done['sms']) && empty($haveSms[$id])) {
            $at = trim((string) ($done['sms_at'] ?? ''));
            $extra[] = $base + [
                'type' => 'sms',
                'at' => $at !== '' ? $at : $interval[0],
            ];
        }
        if (!empty($done['email']) && empty($haveMail[$id])) {
            $at = trim((string) ($done['email_at'] ?? ''));
            $extra[] = $base + [
                'type' => 'mail',
                'at' => $at !== '' ? $at : $interval[0],
                'mail_role' => 'confirm',
            ];
        }
        if (jeab_calendar_log_move_mail_sent($done) && empty($haveMove[$id])) {
            $at = trim((string) ($done['move_email_at'] ?? ''));
            $extra[] = $base + [
                'type' => 'mail',
                'at' => $at !== '' ? $at : $interval[0],
                'mail_role' => 'move',
            ];
        }
    }
    return $extra;
}

function jeab_calendar_log_backfill_sent(JeabStore $store, ?array $sent = null): void
{
    try {
        if ($sent === null) {
            $sent = function_exists('jeab_sms_sent_store') ? jeab_sms_sent_store() : [];
        }
        if (!is_array($sent) || $sent === []) {
            return;
        }
        $have = [];
        foreach (jeab_calendar_log_read()['entries'] as $entry) {
            $type = (string) ($entry['type'] ?? '');
            if ($type !== 'sms' && $type !== 'mail') {
                continue;
            }
            $id = (int) ($entry['booking_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $have[$type . ':' . $id] = true;
            $at = trim((string) ($entry['at'] ?? ''));
            if ($at !== '') {
                $have[$type . ':' . $id . ':' . $at] = true;
            }
        }
        foreach ($sent as $idKey => $done) {
            if (!is_array($done)) {
                continue;
            }
            $id = (int) $idKey;
            if ($id <= 0) {
                continue;
            }
            $row = $store->getBooking($id);
            if (!$row) {
                continue;
            }
            if (!empty($done['sms'])) {
                $at = trim((string) ($done['sms_at'] ?? ''));
                $key = 'sms:' . $id;
                if ($at !== '' && empty($have[$key]) && empty($have[$key . ':' . $at])) {
                    jeab_calendar_log_append('sms', $row, $at);
                    $have[$key] = true;
                    $have[$key . ':' . $at] = true;
                }
            }
            if (!empty($done['email'])) {
                $at = trim((string) ($done['email_at'] ?? ''));
                $key = 'mail:' . $id;
                if ($at !== '' && empty($have[$key]) && empty($have[$key . ':' . $at])) {
                    jeab_calendar_log_append('mail', $row, $at);
                    $have[$key] = true;
                    $have[$key . ':' . $at] = true;
                }
            }
        }
    } catch (Throwable) {
        // Redan skickade sms/mail får inte ta ner kalendern.
    }
}

function jeab_calendar_log_backfill(JeabStore $store): void
{
    try {
        $data = jeab_calendar_log_read();
        if (empty($data['backfilled'])) {
            $have = [];
            foreach ($data['entries'] as $entry) {
                $key = (string) ($entry['type'] ?? '') . ':' . (int) ($entry['booking_id'] ?? 0);
                $have[$key] = true;
            }
            foreach ($store->listAllBookings() as $row) {
                $id = (int) ($row['id'] ?? 0);
                $created = jeab_parse_datetime(isset($row['created_at']) ? (string) $row['created_at'] : null);
                if ($created !== null && empty($have['booked:' . $id])) {
                    jeab_calendar_log_append('booked', $row, $created);
                    $have['booked:' . $id] = true;
                }
                $updated = jeab_parse_datetime(isset($row['updated_at']) ? (string) $row['updated_at'] : null);
                if (
                    ($row['status'] ?? '') === 'cancelled'
                    && $updated !== null
                    && $created !== null
                    && $updated !== $created
                    && empty($have['cancelled:' . $id])
                ) {
                    jeab_calendar_log_append('cancelled', $row, $updated);
                    $have['cancelled:' . $id] = true;
                }
            }
            $fresh = jeab_calendar_log_read();
            $fresh['backfilled'] = true;
            jeab_calendar_log_write($fresh);
        }
        jeab_calendar_log_backfill_sent($store);
    } catch (Throwable) {
        // Backfill får inte ta ner kalendern.
    }
}

/**
 * Samma företag som kalendern man tittar på. Rader vars bokning inte finns
 * kvar kan inte knytas till ett företag och filtreras inte bort här.
 */
function jeab_calendar_log_company_ok(?array $booking, ?string $company): bool
{
    if ($company === null || $company === '' || !is_array($booking) || !function_exists('jeab_booking_company')) {
        return true;
    }
    $want = function_exists('jeab_normalize_company') ? (jeab_normalize_company($company) ?? $company) : $company;
    return jeab_booking_company($booking) === $want;
}

/**
 * Raden kan ligga i veckan: egen tid, förra tiden, eller tid som saknas
 * och fylls från bokningen eller historiken. Färdiga tider utanför veckan tas inte med.
 */
function jeab_calendar_log_might_overlap(array $entry, string $from, string $to): bool
{
    $span = static function (?string $startRaw, ?string $endRaw, bool $missingCounts) use ($from, $to): bool {
        $start = trim((string) $startRaw);
        $end = trim((string) $endRaw);
        $real = preg_match('/^\d{4}-\d{2}-\d{2}/', $start) === 1;
        if (!$real) {
            return $missingCounts;
        }
        if ($start >= $to) {
            return false;
        }
        if ($start >= $from) {
            return true;
        }
        if ($end === '' || preg_match('/^\d{4}-\d{2}-\d{2}/', $end) !== 1) {
            return true;
        }
        return $end > $from;
    };
    if ($span(isset($entry['starts_at']) ? (string) $entry['starts_at'] : null, isset($entry['ends_at']) ? (string) $entry['ends_at'] : null, true)) {
        return true;
    }
    return $span(
        isset($entry['prev_starts_at']) ? (string) $entry['prev_starts_at'] : null,
        isset($entry['prev_ends_at']) ? (string) $entry['prev_ends_at'] : null,
        false
    );
}

function jeab_calendar_log_sql_touch(string $startExpr, string $endExpr, bool $emptyMatches): string
{
    $real = $startExpr . " LIKE '____-__-__%'";
    $open = '(' . $endExpr . " IS NULL OR " . $endExpr . " = '' OR " . $endExpr . " NOT LIKE '____-__-__%')";
    $overlap = '(' . $real . ' AND ' . $startExpr . ' < ? AND (' . $startExpr . ' >= ? OR ' . $open . ' OR ' . $endExpr . ' > ?))';
    if (!$emptyMatches) {
        return $overlap;
    }
    $missing = '(' . $startExpr . " IS NULL OR " . $startExpr . " = '' OR " . $startExpr . " NOT LIKE '____-__-__%')";
    return '(' . $missing . ' OR ' . $overlap . ')';
}

/** @return list<string> */
function jeab_calendar_log_sql_touch_params(string $from, string $to): array
{
    return [$to, $from, $from, $to, $from, $from];
}

function jeab_calendar_log_sql_field(PDO $pdo, string $expr, string $path): string
{
    $quoted = $pdo->quote($path);
    if (function_exists('jeab_log_is_mysql') && jeab_log_is_mysql($pdo)) {
        return 'JSON_UNQUOTE(JSON_EXTRACT(' . $expr . ', ' . $quoted . '))';
    }
    return 'json_extract(' . $expr . ', ' . $quoted . ')';
}

function jeab_calendar_log_sql_touch_on(PDO $pdo, string $expr, string $startPath, string $endPath, string $prevStartPath, string $prevEndPath): string
{
    $main = jeab_calendar_log_sql_touch(
        jeab_calendar_log_sql_field($pdo, $expr, $startPath),
        jeab_calendar_log_sql_field($pdo, $expr, $endPath),
        true
    );
    $prev = jeab_calendar_log_sql_touch(
        jeab_calendar_log_sql_field($pdo, $expr, $prevStartPath),
        jeab_calendar_log_sql_field($pdo, $expr, $prevEndPath),
        false
    );
    return '(' . $main . ' OR ' . $prev . ')';
}

/**
 * @param list<mixed> $payloads
 * @return list<array<string, mixed>>
 */
function jeab_calendar_log_decode_payloads(array $payloads): array
{
    $out = [];
    foreach ($payloads as $payload) {
        if (is_array($payload)) {
            $out[] = $payload;
            continue;
        }
        $row = json_decode((string) $payload, true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

function jeab_calendar_log_sql_has_lines(PDO $pdo): bool
{
    if (!function_exists('jeab_log_table_exists') || !jeab_log_table_exists($pdo, 'log_lines')) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM log_lines WHERE kind = ? LIMIT 1');
    $stmt->execute(['calendar_event']);
    return (bool) $stmt->fetchColumn();
}

/** @return array{table:string}|null */
function jeab_calendar_log_sql_source(PDO $pdo): ?array
{
    if (!function_exists('jeab_log_table_exists')) {
        return null;
    }
    foreach (['log_docs', 'json_docs'] as $table) {
        if (!jeab_log_table_exists($pdo, $table)) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE doc_key = ? LIMIT 1');
        $stmt->execute(['calendar_log']);
        if ($stmt->fetchColumn()) {
            return ['table' => $table];
        }
    }
    return null;
}

/** @return list<array<string, mixed>> */
function jeab_calendar_log_sql_doc_rows(PDO $pdo, string $table, string $where, array $params): array
{
    $mysql = function_exists('jeab_log_is_mysql') && jeab_log_is_mysql($pdo);
    if ($mysql) {
        $sql = 'SELECT jt.entry FROM ' . $table . ', JSON_TABLE(' . $table . '.payload, \'$.entries[*]\' COLUMNS (
            entry JSON PATH \'$\',
            starts_at VARCHAR(40) PATH \'$.starts_at\',
            ends_at VARCHAR(40) PATH \'$.ends_at\',
            prev_starts_at VARCHAR(40) PATH \'$.prev_starts_at\',
            prev_ends_at VARCHAR(40) PATH \'$.prev_ends_at\',
            booking_id VARCHAR(40) PATH \'$.booking_id\',
            entry_id VARCHAR(40) PATH \'$.id\'
        )) AS jt WHERE ' . $table . '.doc_key = ? AND (' . $where . ')';
    } else {
        $sql = 'SELECT j.value FROM ' . $table . ', json_each(' . $table . '.payload, \'$.entries\') AS j WHERE ' . $table . '.doc_key = ? AND (' . $where . ')';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return jeab_calendar_log_decode_payloads($stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** @return list<array<string, mixed>> */
function jeab_calendar_log_sql_line_rows(PDO $pdo, string $where, array $params): array
{
    $sql = 'SELECT payload FROM log_lines WHERE kind = \'calendar_event\' AND (' . $where . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return jeab_calendar_log_decode_payloads($stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Veckans rader ur SQL, plus övriga rader för samma bokningar (historiken).
 * null betyder att filen eller hela dokumentet får läsas som förut.
 *
 * @return list<array<string, mixed>>|null
 */
function jeab_calendar_log_sql_entries(string $from, string $to): ?array
{
    if (!empty($GLOBALS['_JEAB_CALENDAR_LOG_PATH']) || !function_exists('jeab_log_pdo')) {
        return null;
    }
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO) {
            return null;
        }
        $source = jeab_calendar_log_sql_source($pdo);
        $hasLines = jeab_calendar_log_sql_has_lines($pdo);
        if ($source === null && !$hasLines) {
            return null;
        }
        $touchParams = jeab_calendar_log_sql_touch_params($from, $to);
        $docs = [];
        if ($source !== null) {
            $table = $source['table'];
            $expr = (function_exists('jeab_log_is_mysql') && jeab_log_is_mysql($pdo)) ? 'jt.entry' : 'j.value';
            $where = jeab_calendar_log_sql_touch_on($pdo, $expr, '$.starts_at', '$.ends_at', '$.prev_starts_at', '$.prev_ends_at');
            $docs = jeab_calendar_log_sql_doc_rows($pdo, $table, $where, array_merge(['calendar_log'], $touchParams));
        }
        $lines = [];
        if ($hasLines) {
            $where = jeab_calendar_log_sql_touch_on($pdo, 'payload', '$.starts_at', '$.ends_at', '$.prev_starts_at', '$.prev_ends_at');
            $lines = jeab_calendar_log_sql_line_rows($pdo, $where, $touchParams);
        }
        $eventIds = [];
        foreach ($docs as $entry) {
            $eventId = (int) ($entry['id'] ?? 0);
            if ($eventId > 0) {
                $eventIds[$eventId] = $eventId;
            }
        }
        if ($hasLines && $eventIds !== []) {
            $cast = (function_exists('jeab_log_is_mysql') && jeab_log_is_mysql($pdo)) ? 'UNSIGNED' : 'INTEGER';
            $idExpr = 'CAST(' . jeab_calendar_log_sql_field($pdo, 'payload', '$.id') . ' AS ' . $cast . ')';
            $extraLines = [];
            foreach (array_chunk(array_values($eventIds), 400) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $extraLines = array_merge(
                    $extraLines,
                    jeab_calendar_log_sql_line_rows($pdo, $idExpr . ' IN (' . $marks . ')', $chunk)
                );
            }
            $lines = array_merge($lines, $extraLines);
        }
        $provisional = jeab_calendar_log_merge_lists($docs, $lines);
        $bookingIds = [];
        $loose = [];
        foreach ($provisional as $entry) {
            $bookingId = (int) ($entry['booking_id'] ?? 0);
            if ($bookingId > 0) {
                $bookingIds[$bookingId] = $bookingId;
            } else {
                $loose[] = $entry;
            }
        }
        $docAll = [];
        $lineAll = [];
        if ($bookingIds !== []) {
            $cast = (function_exists('jeab_log_is_mysql') && jeab_log_is_mysql($pdo)) ? 'UNSIGNED' : 'INTEGER';
            foreach (array_chunk(array_values($bookingIds), 400) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                if ($source !== null) {
                    $expr = (function_exists('jeab_log_is_mysql') && jeab_log_is_mysql($pdo)) ? 'jt.entry' : 'j.value';
                    $bid = 'CAST(' . jeab_calendar_log_sql_field($pdo, $expr, '$.booking_id') . ' AS ' . $cast . ')';
                    $docAll = array_merge(
                        $docAll,
                        jeab_calendar_log_sql_doc_rows($pdo, $source['table'], $bid . ' IN (' . $marks . ')', array_merge(['calendar_log'], $chunk))
                    );
                }
                if ($hasLines) {
                    $bid = 'CAST(' . jeab_calendar_log_sql_field($pdo, 'payload', '$.booking_id') . ' AS ' . $cast . ')';
                    $lineAll = array_merge(
                        $lineAll,
                        jeab_calendar_log_sql_line_rows($pdo, $bid . ' IN (' . $marks . ')', $chunk)
                    );
                }
            }
        }
        if (isset($GLOBALS['_JEAB_CALENDAR_LOG_SQL_WEEK'])) {
            $GLOBALS['_JEAB_CALENDAR_LOG_SQL_WEEK']++;
        }
        return array_merge($loose, jeab_calendar_log_merge_lists($docAll, $lineAll));
    } catch (Throwable) {
        return null;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_calendar_log_entries_for_week(string $from, string $to): array
{
    $sql = jeab_calendar_log_sql_entries($from, $to);
    if ($sql !== null) {
        return $sql;
    }
    $entries = jeab_calendar_log_read()['entries'];
    return is_array($entries) ? $entries : [];
}

function jeab_calendar_log_for_week(DateTimeImmutable $monday, int $limit = 100, ?JeabStore $store = null, ?string $company = null): array
{
    $from = $monday->setTime(0, 0)->format('Y-m-d H:i:s');
    $to = $monday->modify('+7 days')->setTime(0, 0)->format('Y-m-d H:i:s');
    $entries = jeab_calendar_log_entries_for_week($from, $to);
    $history = jeab_calendar_log_history($entries);
    $bookings = [];
    if ($store instanceof JeabStore) {
        $ids = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !jeab_calendar_log_might_overlap($entry, $from, $to)) {
                continue;
            }
            $id = (int) ($entry['booking_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $bookings = jeab_bookings_by_ids($store, array_values($ids));
    }
    $matched = [];
    foreach ($entries as $entry) {
        if (!is_array($entry) || !jeab_calendar_log_might_overlap($entry, $from, $to)) {
            continue;
        }
        $id = (int) ($entry['booking_id'] ?? 0);
        $booking = ($id > 0 && isset($bookings[$id]) && is_array($bookings[$id])) ? $bookings[$id] : null;
        if (!jeab_calendar_log_company_ok($booking, $company)) {
            continue;
        }
        $entry = jeab_calendar_log_resolve_dates($entry, $booking, $history);
        if (!jeab_calendar_log_in_week($entry, $from, $to)) {
            continue;
        }
        if (trim((string) ($entry['title'] ?? '')) === '' && is_array($booking)) {
            $entry['title'] = jeab_booking_title($booking);
        }
        $matched[] = $entry;
    }
    $snapshot = function_exists('jeab_sms_sent_store') ? jeab_sms_sent_store() : [];
    if (!is_array($snapshot)) {
        $snapshot = [];
    }
    if ($store instanceof JeabStore && $snapshot !== []) {
        foreach (jeab_calendar_log_sent_lines($store, $from, $to, $matched, $snapshot, $company) as $entry) {
            $matched[] = $entry;
        }
    }
    if ($snapshot !== []) {
        $matched = jeab_calendar_log_channel_status($matched, $snapshot);
    }
    $sent = [];
    $other = [];
    foreach ($matched as $entry) {
        $type = (string) ($entry['type'] ?? '');
        if ($type === 'sms' || $type === 'mail') {
            $sent[] = $entry;
        } else {
            $other[] = $entry;
        }
    }
    $sort = static function (array $a, array $b): int {
        $cmp = strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    };
    usort($sent, $sort);
    usort($other, $sort);
    $sent = array_slice($sent, 0, $limit);
    $week = array_merge($sent, array_slice($other, 0, max(0, $limit - count($sent))));
    usort($week, $sort);
    return $week;
}

function jeab_calendar_log_line(array $entry): string
{
    $at = (string) ($entry['at'] ?? '');
    $time = strlen($at) >= 16 ? substr($at, 0, 16) : $at;
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $time) === 1) {
        $time = jeab_calendar_sv_date(substr($time, 0, 10)) . ' ' . substr($time, 11, 5);
    }
    $type = (string) ($entry['type'] ?? '');
    $label = jeab_calendar_log_label($type);
    $role = (string) ($entry['mail_role'] ?? '');
    if ($type === 'mail' && $role === 'confirm') {
        $label = 'Bekräftelsemejl';
    } elseif ($type === 'mail' && $role === 'move') {
        $label = 'Flyttmejl';
    }
    if ($time === '' || $label === '') {
        return '';
    }
    $parts = [$time, $label];
    $title = trim((string) ($entry['title'] ?? ''));
    if ($title !== '') {
        $parts[] = $title;
    }
    $source = jeab_calendar_log_source_label(isset($entry['source']) ? (string) $entry['source'] : null);
    if ($source !== '') {
        $parts[] = $source;
        $email = trim((string) ($entry['grant_email'] ?? ''));
        if ($email !== '') {
            $parts[] = $email;
        }
    }
    // Bokad-raden talar om när bokningen skapades (Webben). Om den också
    // säger «Bekräftelsemejl skickat» ser det ut som att mejlet gick då.
    // Själva utskicket loggas som egen Bekräftelsemejl-rad (cron efter ~2 h).
    // Här märks bara när mejlet fortfarande väntar, så personal kan flytta tid.
    $confirm = (string) ($entry['confirm_mail'] ?? '');
    if ($confirm === 'unsent') {
        $parts[] = 'Bekräftelsemejl inte skickat';
    }
    $move = (string) ($entry['move_mail'] ?? '');
    if ($move === 'sent') {
        $parts[] = 'Flyttmejl skickat';
    } elseif ($move === 'unsent') {
        $parts[] = 'Flyttmejl inte skickat';
    }
    return implode('  ', $parts);
}

/**
 * Kundnamnet i en loggrad (titelns första del före « · »).
 */
function jeab_calendar_log_entry_name(array $entry): string
{
    $title = trim((string) ($entry['title'] ?? ''));
    $parts = explode(' · ', $title);
    return trim((string) ($parts[0] ?? ''));
}

/**
 * Skräp i veckologgen: QA-, test- och påhittade bokningar.
 * 'test' tas bort när raden pekas ut. 'review' tas bara bort när den pekas ut
 * och är ett eget beslut (t.ex. egna provbokningar). '' är en riktig rad.
 */
function jeab_calendar_log_junk_kind(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $lower = mb_strtolower($name, 'UTF-8');
    foreach ([
        '/^qa(?:$|[\s_\-])/u',
        '/(?<![\p{L}])test/u',
        '/(?<![\p{L}])fejk(?![\p{L}])/u',
        '/(?<![\p{L}])probe(?![\p{L}])/u',
        '/(?<![\p{L}])smoke(?![\p{L}])/u',
        '/(?<![\p{L}])dummy(?![\p{L}])/u',
        '/codytest/u',
    ] as $pattern) {
        if (preg_match($pattern, $lower) === 1) {
            return 'test';
        }
    }
    $nonsense = [
        'sdfg', 'dsfg', 'sdgfds', 'asdgasdg', 'kundnamn', 'förnamn och efternamn',
        '01', '12', 'namn', 'namn2', 'hej', 'ff har', 'kund', 'laddare', 'batteri',
        'service', 'sol', 'fjärde', 'outlook meta', 'webb avbok',
    ];
    if (in_array($lower, $nonsense, true)) {
        return 'test';
    }
    $review = [
        'anna andersson', 'bo berg', 'cissi', 'dag', 'eva', 'fia', 'greta', 'hanna', 'ida',
        'pablo', 'sam l', 'demo film',
    ];
    if (in_array($lower, $review, true) || str_contains($lower, 'Demo')) {
        return 'review';
    }
    return '';
}

/**
 * Kandidater för städning med bokningens läge, så att beslutet kan tas på
 * riktiga data. En rad vars bokning finns kvar, inte är avbokad och har samma
 * namn skyddas (den syns ju i kalendern). Sms/mejl som backfill skulle lägga
 * tillbaka från sms-loggen markeras och tas inte bort.
 *
 * @return list<array<string, mixed>>
 */
function jeab_calendar_log_cleanup_candidates(array $entries, ?JeabStore $store): array
{
    $sent = function_exists('jeab_sms_sent_store') ? jeab_sms_sent_store() : [];
    if (!is_array($sent)) {
        $sent = [];
    }
    $orderBookings = [];
    if (function_exists('jeab_purchase_all')) {
        try {
            foreach (jeab_purchase_all() as $purchase) {
                if (is_array($purchase) && (int) ($purchase['booking_id'] ?? 0) > 0) {
                    $orderBookings[(int) $purchase['booking_id']] = true;
                }
            }
        } catch (Throwable) {
            $orderBookings = [];
        }
    }
    $out = [];
    $bookings = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entryId = (int) ($entry['id'] ?? 0);
        $name = jeab_calendar_log_entry_name($entry);
        $kind = jeab_calendar_log_junk_kind($name);
        if ($kind === '' || $entryId <= 0) {
            continue;
        }
        $bookingId = (int) ($entry['booking_id'] ?? 0);
        $booking = null;
        if ($store instanceof JeabStore && $bookingId > 0) {
            if (!array_key_exists($bookingId, $bookings)) {
                $bookings[$bookingId] = $store->getBooking($bookingId);
            }
            $booking = is_array($bookings[$bookingId]) ? $bookings[$bookingId] : null;
        }
        $bookingName = is_array($booking) ? trim((string) ($booking['customer_name'] ?? '')) : '';
        $bookingStatus = is_array($booking) ? (string) ($booking['status'] ?? '') : '';
        $protected = is_array($booking)
            && $bookingStatus !== 'cancelled'
            && mb_strtolower($bookingName, 'UTF-8') === mb_strtolower($name, 'UTF-8');
        $type = (string) ($entry['type'] ?? '');
        $readd = ($type === 'sms' || $type === 'mail')
            && is_array($booking)
            && is_array($sent[(string) $bookingId] ?? null);
        $out[] = [
            'id' => $entryId,
            'kind' => $kind,
            'type' => $type,
            'at' => (string) ($entry['at'] ?? ''),
            'title' => (string) ($entry['title'] ?? ''),
            'booking_id' => $bookingId,
            'starts_at' => (string) ($entry['starts_at'] ?? ''),
            'ends_at' => (string) ($entry['ends_at'] ?? ''),
            'booking' => is_array($booking) ? [
                'name' => $bookingName,
                'status' => $bookingStatus,
                'starts_at' => (string) ($booking['starts_at'] ?? ''),
                'ends_at' => (string) ($booking['ends_at'] ?? ''),
                'has_address' => trim((string) ($booking['address'] ?? '')) !== '',
                'has_order' => !empty($orderBookings[$bookingId]),
            ] : null,
            'protected' => $protected,
            'readd' => $readd,
        ];
    }
    return $out;
}

/**
 * Skriver en kopia av hela loggen utanför webbroten (privat/backups) eller
 * under data/.private/backups. Returnerar sökvägen relativt webbrotens förälder.
 */
function jeab_calendar_log_backup(array $data): string
{
    if (function_exists('jeab_legacy_json_skip') && jeab_legacy_json_skip('calendar_log')) {
        return '';
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Loggen kunde inte kopieras.');
    }
    $dirs = [];
    if (!empty($GLOBALS['_JEAB_CALENDAR_LOG_BACKUP_DIR'])) {
        $dirs[] = (string) $GLOBALS['_JEAB_CALENDAR_LOG_BACKUP_DIR'];
    } else {
        if (defined('JEAB_ROOT')) {
            $dirs[] = dirname(JEAB_ROOT) . '/privat/backups';
        }
        $dirs[] = jeab_data_dir() . '/backups';
    }
    $stamp = (new DateTimeImmutable('now'))->format('Ymd-His');
    foreach ($dirs as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            continue;
        }
        if (!is_writable($dir)) {
            continue;
        }
        $path = $dir . '/calendar-log-' . $stamp . '-' . bin2hex(random_bytes(3)) . '.json';
        $written = @file_put_contents($path, $json, LOCK_EX);
        if ($written === strlen($json) && is_file($path) && (int) filesize($path) === strlen($json)) {
            @chmod($path, 0600);
            if (defined('JEAB_ROOT')) {
                $base = dirname(JEAB_ROOT) . '/';
                if (str_starts_with($path, $base)) {
                    return substr($path, strlen($base));
                }
            }
            return $path;
        }
        @unlink($path);
    }
    throw new RuntimeException('Ingen kopia kunde sparas. Inget ändrat.');
}

/**
 * Engångsstädning av veckologgen. dry=true ändrar inget och listar bara.
 * Annars tas bara utpekade rader bort som också är kandidater, inte skyddade
 * och inte skulle läggas tillbaka. Kopia sparas först, annars ändras inget.
 *
 * @param list<int> $ids
 * @return array<string, mixed>
 */
function jeab_calendar_log_cleanup(?JeabStore $store, array $ids, bool $dry): array
{
    $data = jeab_calendar_log_read();
    $candidates = jeab_calendar_log_cleanup_candidates($data['entries'], $store);
    if ($dry) {
        return ['dry' => true, 'total' => count($data['entries']), 'candidates' => $candidates];
    }
    $want = [];
    foreach ($ids as $id) {
        if ((int) $id > 0) {
            $want[(int) $id] = true;
        }
    }
    $remove = [];
    $removed = [];
    foreach ($candidates as $row) {
        $id = (int) $row['id'];
        if (empty($want[$id]) || !empty($row['protected']) || !empty($row['readd'])) {
            continue;
        }
        $remove[$id . "\n" . $row['title'] . "\n" . $row['at'] . "\n" . $row['type']] = true;
        $removed[] = ['id' => $id, 'type' => $row['type'], 'at' => $row['at'], 'title' => $row['title'], 'starts_at' => $row['starts_at']];
    }
    if ($remove === []) {
        return ['dry' => false, 'total' => count($data['entries']), 'removed' => [], 'backup' => ''];
    }
    $before = count($data['entries']);
    $backup = jeab_calendar_log_backup($data);
    $keep = [];
    foreach ($data['entries'] as $entry) {
        $key = (int) ($entry['id'] ?? 0) . "\n" . (string) ($entry['title'] ?? '') . "\n" . (string) ($entry['at'] ?? '') . "\n" . (string) ($entry['type'] ?? '');
        if (!empty($remove[$key])) {
            continue;
        }
        $keep[] = $entry;
    }
    $data['entries'] = $keep;
    jeab_calendar_log_write($data);
    $dropIds = [];
    foreach ($removed as $row) {
        $dropIds[] = (int) ($row['id'] ?? 0);
    }
    jeab_calendar_log_rows_drop_ids($dropIds);
    return [
        'dry' => false,
        'total_before' => $before,
        'total_after' => count($keep),
        'removed' => $removed,
        'backup' => $backup,
    ];
}


/**
 * Outlook-händelser som JEAB lagt in för en bokning (booking_outlook_events).
 * Med $fetch hämtas händelsen (bara GET) så att titel och deltagare
 * kan jämföras innan något tas bort.
 *
 * @return list<array<string, mixed>>
 */
function jeab_booking_outlook_links(int $bookingId, ?array $row, bool $fetch): array
{
    if ($bookingId <= 0 || !function_exists('jeab_outlook_events_for_booking')) {
        return [];
    }
    $out = [];
    foreach (jeab_outlook_events_for_booking($bookingId) as $stored) {
        $email = (string) ($stored['email'] ?? '');
        $eventId = (string) ($stored['graph_event_id'] ?? '');
        if ($email === '' || $eventId === '') {
            continue;
        }
        $link = [
            'grant_email' => $email,
            'grant_name' => (string) ($stored['resource'] ?? $email),
            'grant_company' => '',
            'calendar_id' => 'primary',
            'calendar_name' => '',
            'event_id' => $eventId,
            'fetched' => false,
        ];
        if ($fetch && function_exists('jeab_graph_api')) {
            $res = jeab_graph_api('GET', 'users/' . rawurlencode($email) . '/events/' . rawurlencode($eventId) . '?$select=id,subject,attendees');
            $event = is_array($res['body'] ?? null) ? $res['body'] : [];
            if ((int) ($res['code'] ?? 0) === 200 && isset($event['subject'])) {
                $participants = [];
                foreach ((array) ($event['attendees'] ?? []) as $person) {
                    $mail = strtolower(trim((string) (is_array($person) ? ($person['emailAddress']['address'] ?? '') : '')));
                    if ($mail !== '' && $mail !== strtolower($email)) {
                        $participants[] = $mail;
                    }
                }
                $title = (string) ($event['subject'] ?? '');
                $link['fetched'] = true;
                $link['title'] = $title;
                $link['event_calendar_id'] = '';
                $link['when'] = null;
                $link['other_participants'] = count($participants);
                $link['title_matches'] = is_array($row) && $title !== '' && function_exists('jeab_gp_event_title') && $title === jeab_gp_event_title($row);
            }
        }
        $out[] = $link;
    }
    return $out;
}

/** Namnet som radering jämför med. Tomt bokningsnamn läses från den länkade kontakten. */
function jeab_booking_delete_confirm_name(array $row): string
{
    $name = trim((string) ($row['customer_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    if ($contactId === '' || !function_exists('jeab_kontakt_get')) {
        return '';
    }
    $contact = jeab_kontakt_get($contactId);
    if (!is_array($contact) || !function_exists('jeab_kontakt_row_name')) {
        return '';
    }

    return trim(jeab_kontakt_row_name($contact));
}

function jeab_booking_delete_silent(JeabStore $store, int $id, string $confirmName, bool $dry): array
{
    $row = $store->getBooking($id);
    if (!$row) {
        throw new InvalidArgumentException('Bokningen finns inte.');
    }
    $log = jeab_calendar_log_read();
    $entries = [];
    foreach ($log['entries'] as $entry) {
        if (is_array($entry) && (int) ($entry['booking_id'] ?? 0) === $id) {
            $entries[] = $entry;
        }
    }
    $links = jeab_booking_outlook_links($id, $row, !$dry);
    $reminder = $store->findOpenReminderByBooking($id);
    $orders = [];
    if (function_exists('jeab_purchase_all')) {
        try {
            foreach (jeab_purchase_all() as $purchase) {
                if (is_array($purchase) && (int) ($purchase['booking_id'] ?? 0) === $id) {
                    $orders[] = (int) ($purchase['id'] ?? 0);
                }
            }
        } catch (Throwable) {
            $orders = [];
        }
    }
    if ($dry) {
        return [
            'dry' => true,
            'id' => $id,
            'counts' => [
                'log_entries' => count($entries),
                'reminders' => is_array($reminder) ? 1 : 0,
                'orders' => count($orders),
                'outlook' => count($links),
            ],
        ];
    }
    $summary = [
        'id' => $id,
        'name' => (string) ($row['customer_name'] ?? ''),
        'company' => jeab_booking_company($row),
        'assignees' => jeab_assignees_from_row($row),
        'starts_at' => (string) ($row['starts_at'] ?? ''),
        'ends_at' => (string) ($row['ends_at'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'has_email' => trim((string) ($row['email'] ?? '')) !== '',
        'has_phone' => trim((string) ($row['phone'] ?? '')) !== '',
        'has_address' => trim((string) ($row['address'] ?? '')) !== '',
        'created_at' => (string) ($row['created_at'] ?? ''),
        'log_entries' => count($entries),
        'reminder_id' => is_array($reminder) ? (int) ($reminder['id'] ?? 0) : 0,
        'orders' => $orders,
    ];
    $expected = jeab_booking_delete_confirm_name($row);
    if (mb_strtolower(trim($confirmName), 'UTF-8') === '' || mb_strtolower(trim($confirmName), 'UTF-8') !== mb_strtolower($expected, 'UTF-8')) {
        throw new InvalidArgumentException('Namnet stämmer inte. Inget ändrat.');
    }
    if ($orders !== [] || $summary['reminder_id'] > 0) {
        throw new InvalidArgumentException('Bokningen hör till en order eller påminnelse. Inget ändrat.');
    }
    $backupPath = jeab_calendar_log_backup([
        'kind' => 'booking.delete_silent',
        'at' => jeab_now(),
        'booking' => $row,
        'calendar_log_entries' => $entries,
        'outlook' => $links,
    ]);
    $backupPath = jeab_booking_delete_rename_backup($backupPath, $id);

    $outlook = [];
    foreach ($links as $link) {
        $done = ['grant_name' => $link['grant_name'], 'event_id' => $link['event_id'], 'deleted' => false, 'reason' => jeab_booking_delete_outlook_block($link)];
        if ($done['reason'] === '') {
            $email = (string) ($link['grant_email'] ?? '');
            if ($email !== '' && function_exists('jeab_gp_delete_event')) {
                jeab_gp_delete_event($email, $id);
                $done['deleted'] = function_exists('jeab_outlook_event_id') && jeab_outlook_event_id($id, $email) === '';
                if (!$done['deleted']) {
                    $done['reason'] = 'Outlook svarade inte ok.';
                }
            }
        }
        $outlook[] = $done;
    }

    $deleted = method_exists($store, 'deleteBooking') ? (bool) $store->deleteBooking($id) : false;
    if (!$deleted) {
        throw new RuntimeException('Bokningen kunde inte tas bort. Kopia: ' . $backupPath);
    }
    $removedLog = 0;
    if ($entries !== []) {
        $fresh = jeab_calendar_log_read();
        $keep = [];
        $dropIds = [];
        foreach ($fresh['entries'] as $entry) {
            if (is_array($entry) && (int) ($entry['booking_id'] ?? 0) === $id) {
                $removedLog++;
                $dropIds[] = (int) ($entry['id'] ?? 0);
                continue;
            }
            $keep[] = $entry;
        }
        $fresh['entries'] = $keep;
        jeab_calendar_log_write($fresh);
        jeab_calendar_log_rows_drop_ids($dropIds);
    }
    if (function_exists('jeab_gp_week_events_cache_forget')) {
        jeab_gp_week_events_cache_forget();
    }
    return [
        'dry' => false,
        'booking' => $summary,
        'backup' => $backupPath,
        'deleted' => true,
        'log_removed' => $removedLog,
        'outlook' => $outlook,
    ];
}

/** Tom sträng = JEAB:s egen testhändelse som får tas bort; annars varför den lämnas. */
function jeab_booking_delete_outlook_block(array $link): string
{
    if (empty($link['fetched'])) {
        return 'Händelsen kunde inte läsas, rörs inte.';
    }
    if (empty($link['title_matches'])) {
        return 'Titeln är inte bokningens, rörs inte.';
    }
    if ((int) ($link['other_participants'] ?? 0) > 0) {
        return 'Har deltagare, rörs inte (skulle avisera).';
    }
    return '';
}

function jeab_booking_delete_rename_backup(string $relative, int $id): string
{
    $abs = $relative;
    if (!str_starts_with($relative, '/') && defined('JEAB_ROOT')) {
        $abs = dirname(JEAB_ROOT) . '/' . $relative;
    }
    $target = str_replace('/calendar-log-', '/booking-' . $id . '-', $abs);
    if ($target !== $abs && @rename($abs, $target)) {
        return str_replace('/calendar-log-', '/booking-' . $id . '-', $relative);
    }
    return $relative;
}

/* —— Kalender v2: svenska datum, röda dagar, interna poster, utanför 07–17 —— */

/** «5 okt» (år bara när det inte är innevarande år). */
function jeab_calendar_sv_date(string $ymd, bool $withWeekday = false): string
{
    try {
        $dt = new DateTimeImmutable(substr($ymd, 0, 10));
    } catch (Exception) {
        return $ymd;
    }
    $months = [1 => 'jan', 2 => 'feb', 3 => 'mar', 4 => 'apr', 5 => 'maj', 6 => 'jun', 7 => 'jul', 8 => 'aug', 9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec'];
    $label = $dt->format('j') . ' ' . $months[(int) $dt->format('n')];
    if ($dt->format('Y') !== (new DateTimeImmutable('now'))->format('Y')) {
        $label .= ' ' . $dt->format('Y');
    }
    return $withWeekday ? jeab_sv_weekday($dt, true) . ' ' . $label : $label;
}

/** «07–11», «07:30–11» */
function jeab_calendar_time_label(string $startsAt, string $endsAt): string
{
    $start = jeab_month_clock($startsAt);
    $end = jeab_month_clock($endsAt);
    if ($start === '') {
        return '';
    }
    return $end !== '' ? $start . '–' . $end : $start;
}

/** «mån 2 nov · 07:00–11:00», heldag «mån 2 nov–fre 6 nov». */
function jeab_calendar_when_label(string $startsAt, string $endsAt): string
{
    try {
        $start = new DateTimeImmutable($startsAt);
        $end = new DateTimeImmutable($endsAt);
    } catch (Exception) {
        return '';
    }
    if (jeab_booking_is_all_day($startsAt, $endsAt)) {
        $last = $end->modify('-1 day');
        $first = jeab_calendar_sv_date($start->format('Y-m-d'), true);
        if ($last->format('Y-m-d') <= $start->format('Y-m-d')) {
            return $first . ' · heldag';
        }
        return $first . '–' . jeab_calendar_sv_date($last->format('Y-m-d'), true);
    }
    return jeab_calendar_sv_date($start->format('Y-m-d'), true) . ' · ' . $start->format('H:i') . '–' . $end->format('H:i');
}

function jeab_calendar_order_key(string $startsAt): int
{
    try {
        $dt = new DateTimeImmutable($startsAt);
    } catch (Exception) {
        return 0;
    }
    return ((int) $dt->format('H')) * 60 + (int) $dt->format('i');
}

/**
 * Röda dagar (helgdagar, jul- och nyårsafton) mellan två datum.
 * Samma lista som Ordrar använder för första lediga tid (#264).
 *
 * @return array<string, string> Y-m-d => namn
 */
function jeab_calendar_red_days(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    if (!function_exists('jeab_ordrar_se_holidays')) {
        return [];
    }
    $out = [];
    for ($y = (int) $from->format('Y'); $y <= (int) $to->format('Y'); $y++) {
        foreach (jeab_ordrar_se_holidays($y) as $day => $name) {
            if ($day >= $from->format('Y-m-d') && $day <= $to->format('Y-m-d')) {
                $out[$day] = $name;
            }
        }
    }
    return $out;
}

/**
 * Interna Outlook-poster (kickoff, ADMIN …) som ligger i flera kalendrar samtidigt
 * slås ihop till en rad med alla namn.
 */
function jeab_calendar_merge_outlook(array $events): array
{
    $out = [];
    $index = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $key = jeab_calendar_compact_text((string) ($event['title'] ?? '')) . '|'
            . (string) ($event['starts_at'] ?? '') . '|' . (string) ($event['ends_at'] ?? '') . '|' . (!empty($event['all_day']) ? '1' : '0');
        $who = trim((string) ($event['who'] ?? ''));
        if (isset($index[$key])) {
            $i = $index[$key];
            $names = array_values(array_filter(array_map('trim', explode(',', (string) ($out[$i]['who'] ?? '')))));
            if ($who !== '' && !in_array($who, $names, true)) {
                $names[] = $who;
            }
            $out[$i]['who'] = implode(', ', $names);
            continue;
        }
        $index[$key] = count($out);
        $out[] = $event;
    }
    return $out;
}

function jeab_calendar_outlook_inner_html(array $event, bool $allDay): string
{
    $title = trim((string) ($event['title'] ?? ''));
    $who = trim((string) ($event['who'] ?? ''));
    $time = $allDay ? '' : jeab_calendar_time_label((string) ($event['starts_at'] ?? ''), (string) ($event['ends_at'] ?? ''));
    $html = $time !== '' ? '<span class="wg-time">' . e($time) . '</span>' : '';
    $html .= '<strong class="wg-lead">' . e($title !== '' ? $title : 'Upptagen') . '</strong>';
    if ($who !== '') {
        $html .= '<span class="wg-meta">' . e($who) . '</span>';
    }
    return $html;
}

/**
 * Bokningar och Outlook-poster som helt ligger före 07 eller efter 17 en vardag.
 * Visas som en kort rad ovanför/under dagen («19:00 Privat»), inte i rutnätet.
 *
 * @param list<DateTimeImmutable> $days
 * @return array{early: array<string, list<string>>, late: array<string, list<string>>}
 */
function jeab_calendar_outside_hours(array $bookings, array $outlookEvents, array $days): array
{
    $early = [];
    $late = [];
    $items = [];
    foreach ($bookings as $row) {
        if (is_array($row)) {
            $items[] = ['kind' => 'booking', 'starts' => (string) ($row['starts_at'] ?? ''), 'ends' => (string) ($row['ends_at'] ?? ''), 'row' => $row];
        }
    }
    foreach ($outlookEvents as $event) {
        if (is_array($event) && empty($event['all_day'])) {
            $items[] = ['kind' => 'outlook', 'starts' => (string) ($event['starts_at'] ?? ''), 'ends' => (string) ($event['ends_at'] ?? ''), 'event' => $event];
        }
    }
    foreach ($days as $day) {
        $key = $day->format('Y-m-d');
        $gridStart = $day->setTime(7, 0);
        $gridEnd = $day->setTime(17, 0);
        foreach ($items as $item) {
            if (jeab_booking_is_all_day($item['starts'], $item['ends'])) {
                continue;
            }
            try {
                $start = new DateTimeImmutable($item['starts']);
                $end = new DateTimeImmutable($item['ends']);
            } catch (Exception) {
                continue;
            }
            if ($start->format('Y-m-d') !== $key) {
                continue;
            }
            if ($end > $gridStart && $start < $gridEnd) {
                continue;
            }
            $clock = $start->format('H:i');
            if ($item['kind'] === 'booking') {
                $row = $item['row'];
                $label = jeab_booking_address_lead($row['address'] ?? null);
                if ($label === '') {
                    $label = trim((string) ($row['customer_name'] ?? '')) ?: 'Bokning';
                }
                $html = '<button type="button" class="wg-out-item ' . e(jeab_booking_job_class($row)) . '" data-js="edit-booking" data-wave="bookings"' . jeab_booking_data_attrs($row) . '>'
                    . '<span class="wg-out-time">' . e($clock) . '</span> ' . e($label) . '</button>';
            } else {
                $event = $item['event'];
                $title = trim((string) ($event['title'] ?? '')) ?: 'Upptagen';
                $who = trim((string) ($event['who'] ?? ''));
                $linked = jeab_calendar_outlook_booking_row($event);
                if ($linked !== null) {
                    $html = '<button type="button" class="wg-out-item ' . e(jeab_booking_job_class($linked)) . '" data-js="edit-booking" data-wave="bookings"' . jeab_booking_data_attrs($linked) . '>'
                        . '<span class="wg-out-time">' . e($clock) . '</span> ' . e($title) . '</button>';
                } else {
                    $html = '<span class="wg-out-item is-internal" data-js="outlook-busy" data-wave="outlook" title="' . e($who) . '"' . jeab_calendar_busy_data_attrs($event) . '>'
                        . '<span class="wg-out-time">' . e($clock) . '</span> ' . e($title) . '</span>';
                }
            }
            if ($start >= $gridEnd) {
                $late[$key][] = [$clock, $html];
            } else {
                $early[$key][] = [$clock, $html];
            }
        }
    }
    $flat = static function (array $byDay): array {
        $out = [];
        foreach ($byDay as $key => $list) {
            usort($list, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
            $out[$key] = array_map(static fn(array $pair): string => $pair[1], $list);
        }
        return $out;
    };
    return ['early' => $flat($early), 'late' => $flat($late)];
}

/**
 * Länk från en bokning till ordern (v3) eller påminnelsen.
 *
 * @return array{order:string, reminder:string}
 */
function jeab_calendar_booking_links(array $row): array
{
    static $byBooking = null;
    $empty = ['order' => '', 'reminder' => ''];
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0) {
        return $empty;
    }
    if ($byBooking === null || !empty($GLOBALS['_JEAB_CAL_LINKS_RESET'])) {
        $byBooking = [];
        unset($GLOBALS['_JEAB_CAL_LINKS_RESET']);
        if (function_exists('jeab_purchase_booking_ids')) {
            try {
                $byBooking = jeab_purchase_booking_ids();
            } catch (Throwable) {
                $byBooking = [];
            }
        }
    }
    $company = function_exists('jeab_booking_company') ? (jeab_booking_company($row) ?? jeab_selected_company()) : jeab_selected_company();
    $reminderFromOrder = (int) ($byBooking[$id] ?? 0);
    if ($reminderFromOrder > 0 && function_exists('jeab_ordrar_url')) {
        return ['order' => jeab_ordrar_url(['co' => $company, 'id' => $reminderFromOrder]), 'reminder' => ''];
    }
    $reminderId = (int) ($row['reminder_id'] ?? 0);
    if ($reminderId <= 0 && function_exists('jeab_store')) {
        try {
            $reminder = jeab_store()->findOpenReminderByBooking($id);
            $reminderId = is_array($reminder) ? (int) ($reminder['id'] ?? 0) : 0;
        } catch (Throwable) {
            $reminderId = 0;
        }
    }
    if ($reminderId > 0 && function_exists('jeab_reminders_url')) {
        return ['order' => '', 'reminder' => jeab_reminders_url(['co' => $company, 'open' => $reminderId, 'who' => 'all', 'q' => ''])];
    }
    return $empty;
}
