<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';
/** Klara-listan visar så här många rader först; resten via «Visa fler». */
const JEAB_REMINDER_DONE_PAGE = 50;
/** Sync får känna till så här många redan ritade kort. HTML byggs bara för de som ändrats, högst en sida. */
const JEAB_REMINDER_SYNC_WINDOW = 200;
/** jeab_prune_due läser intervallet. Sidan och tick raderar inte klara påminnelser. */
const JEAB_REMINDER_PRUNE_INTERVAL = 600;
/** Högst så här många Lantmäteri-uppslag av ort per sidladdning. */
const JEAB_REMINDER_ORT_LOOKUPS_PER_REQUEST = 1;
/** Ort som inte hittades slås upp igen tidigast efter så här många sekunder. */
const JEAB_REMINDER_ORT_MISS_TTL = 604800;
/** Flik- och personräknare återanvänds så här länge. Skrivning tömmer cachen. Listan själv läses varje gång. */
const JEAB_REMINDER_COUNT_TTL = 600;

function jeab_list_reminders(JeabStore $store, bool $includeCompleted = true, ?string $company = null, ?string $person = null, ?int $limit = null, int $offset = 0, bool $withNote = true): array
{
    $company = $company !== null ? (jeab_normalize_company($company) ?? null) : null;
    $person = $person !== null ? trim($person) : null;
    if ($person === '') {
        $person = null;
    }
    if ($store instanceof JeabPdoStore && ($company !== null || $person !== null)) {
        $rows = $store->listRemindersFiltered($includeCompleted, $company, $person, $limit, $offset, $withNote);
    } elseif ($store instanceof JeabPdoStore) {
        $rows = $store->listReminders($includeCompleted, $limit, $offset, $withNote);
    } elseif ($store instanceof JeabJsonStore) {
        $rows = $store->listReminders($includeCompleted);
        if ($company !== null || $person !== null) {
            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($company, $person): bool {
                    if ($company !== null && jeab_reminder_company($row) !== $company) {
                        return false;
                    }
                    return $person === null || jeab_reminder_matches_who($row, $person);
                }
            ));
        }
        $rows = jeab_rows_limit($rows, $limit, $offset);
    } else {
        $rows = $store->listReminders($includeCompleted);
        if ($company !== null || $person !== null) {
            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($company, $person): bool {
                    if ($company !== null && jeab_reminder_company($row) !== $company) {
                        return false;
                    }
                    return $person === null || jeab_reminder_matches_who($row, $person);
                }
            ));
        }
        if ($limit !== null && $limit > 0) {
            $rows = jeab_rows_limit($rows, $limit, $offset);
        }
    }
    if (!$withNote && $store instanceof JeabPdoStore) {
        foreach ($rows as $i => $row) {
            if (is_array($row)) {
                $rows[$i]['_jeab_list_slim'] = true;
            }
        }
    }
    return $rows;
}

/**
 * Bolagets lista till Påminnelser. Utan sök hoppas note över i SQL.
 * Anteckningen hämtas sedan bara för raderna som ritas.
 *
 * @return list<array<string, mixed>>
 */
function jeab_reminders_load_company(JeabStore $store, ?string $company, string $search): array
{
    $company = jeab_normalize_company($company) ?? (function_exists('jeab_selected_company') ? jeab_selected_company() : 'jarfalla');
    $withNote = trim($search) !== '';
    $started = microtime(true);
    $rows = jeab_list_reminders($store, true, $company, null, null, 0, $withNote);
    jeab_reminder_list_diag_note('list', microtime(true) - $started, count($rows));

    return $rows;
}

/**
 * Sätter tillbaka note på de rader listan ritade. Övriga fält och ordning står kvar.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function jeab_reminders_fill_bodies(JeabStore $store, array $rows): array
{
    $started = microtime(true);
    $ids = [];
    $slim = false;
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['_jeab_list_slim'])) {
            continue;
        }
        $slim = true;
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $found = [];
    if ($ids !== [] && $store instanceof JeabPdoStore) {
        $found = $store->reminderRowsByIds(array_values($ids));
    }
    if (!$slim) {
        jeab_reminder_list_diag_note('fill', microtime(true) - $started, 0);

        return $rows;
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            $out[] = $row;
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $full = $found[$id] ?? null;
        if (is_array($full)) {
            if (!empty($row['_jeab_open_extra'])) {
                $full['_jeab_open_extra'] = true;
            }
            $out[] = $full;
            continue;
        }
        unset($row['_jeab_list_slim']);
        $out[] = $row;
    }
    jeab_reminder_list_diag_note('fill', microtime(true) - $started, count($ids));

    return $out;
}

/** Minnesnotering för listans tider. Skriver ingen fil och skickar inget. */
function jeab_reminder_list_diag_note(string $key, float $seconds, int $rows = 0): void
{
    if (empty($GLOBALS['_JEAB_REMINDER_LIST_DIAG'])) {
        return;
    }
    $key = preg_replace('/[^a-z0-9_-]/i', '', $key) ?? '';
    if ($key === '') {
        return;
    }
    if (!isset($GLOBALS['_JEAB_REMINDER_LIST_DIAG_ROWS']) || !is_array($GLOBALS['_JEAB_REMINDER_LIST_DIAG_ROWS'])) {
        $GLOBALS['_JEAB_REMINDER_LIST_DIAG_ROWS'] = [];
    }
    $GLOBALS['_JEAB_REMINDER_LIST_DIAG_ROWS'][$key] = [
        'ms' => round(max(0, $seconds) * 1000, 1),
        'rows' => $rows,
    ];
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('rem_' . $key, $seconds);
    }
}

/** @return array<string, array{ms:float,rows:int}> */
function jeab_reminder_list_diag(): array
{
    $raw = $GLOBALS['_JEAB_REMINDER_LIST_DIAG_ROWS'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $key => $row) {
        if (!is_string($key) || !is_array($row)) {
            continue;
        }
        $out[$key] = [
            'ms' => (float) ($row['ms'] ?? 0),
            'rows' => (int) ($row['rows'] ?? 0),
        ];
    }

    return $out;
}

/** Alla listor, öppna och klara. Sidvisning i gränssnittet används inte. */
function jeab_reminders_every(JeabStore $store, ?int $limit = null, int $offset = 0): array
{
    if ($store instanceof JeabPdoStore || $store instanceof JeabJsonStore) {
        $rows = $store->listEveryReminder($limit, $offset);
        return is_array($rows) ? array_values($rows) : [];
    }
    if (method_exists($store, 'listEveryReminder')) {
        $rows = $store->listEveryReminder();
        return is_array($rows) ? array_values($rows) : [];
    }
    return $store->listReminders(true);
}

function jeab_reminder_is_trashed(array $row): bool
{
    if (trim((string) ($row['deleted_at'] ?? '')) !== '') {
        return true;
    }
    return !empty($row['trashed']);
}

function jeab_reminder_phone_key(string $phone): string
{
    $norm = jeab_norm_phone($phone);
    if ($norm !== '') {
        return ltrim(substr($norm, 3), '0');
    }
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '46') && strlen($digits) > 9) {
        $digits = substr($digits, 2);
    }
    return ltrim($digits, '0');
}

/**
 * Nålar för produkt i rubrik och taggar. Samma ordning som jeab_inkorg_reminder_offer.
 *
 * @return list<string>
 */
function jeab_reminder_offer_needles(string $offer): array
{
    return match ($offer) {
        'sol' => ['solcell'],
        'batteri' => ['batter'],
        'laddbox' => ['elbil', 'laddbox', 'laddare'],
        default => [],
    };
}

/**
 * Uppslag på index reminders_contact_id, sedan produkt i rubrik och taggar.
 * Produkten är ingen kolumn. PHP bekräftar träffen med jeab_inkorg_reminder_offer.
 *
 * @return array{0:string,1:list<string>}
 */
function jeab_reminder_contact_product_sql(string $driver, string $offer): array
{
    $needles = jeab_reminder_offer_needles($offer);
    if ($needles === []) {
        return ['', []];
    }
    $expr = $driver === 'mysql'
        ? "LOWER(CONCAT(IFNULL(title, ''), ' ', IFNULL(tags, '')))"
        : "lower(ifnull(title, '') || ' ' || ifnull(tags, ''))";
    $piece = $driver === 'mysql'
        ? 'LOCATE(?, ' . $expr . ') > 0'
        : 'instr(' . $expr . ', ?) > 0';
    $parts = array_fill(0, count($needles), $piece);

    return [
        'SELECT * FROM reminders WHERE contact_id = ? AND id > 0 AND (' . implode(' OR ', $parts) . ')',
        $needles,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_reminder_rows_for_contact_product(JeabStore $store, string $contactId, string $offer): array
{
    if ($store instanceof JeabPdoStore) {
        $driver = (string) $store->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        [$sql, $needles] = jeab_reminder_contact_product_sql($driver === 'mysql' ? 'mysql' : 'sqlite', $offer);
        if ($sql === '') {
            return [];
        }
        $stmt = $store->pdo()->prepare($sql);
        $stmt->execute(array_merge([$contactId], $needles));
        $fetched = $stmt->fetchAll();
        $GLOBALS['_JEAB_REMINDER_PRODUCT_SQL'] = (int) ($GLOBALS['_JEAB_REMINDER_PRODUCT_SQL'] ?? 0) + 1;
        $rows = [];
        foreach (is_array($fetched) ? $fetched : [] as $row) {
            if (is_array($row)) {
                $rows[] = jeab_hydrate_reminder($row);
            }
        }

        return $rows;
    }
    $GLOBALS['_JEAB_REMINDER_PRODUCT_SCAN'] = (int) ($GLOBALS['_JEAB_REMINDER_PRODUCT_SCAN'] ?? 0) + 1;
    $rows = [];
    foreach (jeab_reminders_every($store) as $row) {
        if (!is_array($row) || trim((string) ($row['contact_id'] ?? '')) !== $contactId) {
            continue;
        }
        if (jeab_inkorg_reminder_offer($row) !== $offer) {
            continue;
        }
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Öppen rad vinner över en klar. Annars den äldsta.
 *
 * @param array<string, mixed>|null $best
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_reminder_prefer_customer_product(?array $best, array $row): array
{
    if ($best === null) {
        return $row;
    }
    $bestOpen = empty($best['completed_at']);
    $rowOpen = empty($row['completed_at']);
    if ($rowOpen && !$bestOpen) {
        return $row;
    }
    if ($rowOpen === $bestOpen && (int) ($row['id'] ?? 0) < (int) ($best['id'] ?? 0)) {
        return $row;
    }

    return $best;
}

/**
 * Samma kund och samma produkt, i alla påminnelser utom papperskorgen.
 * CONTACTS_SOLE med contact_id matchar bara den kontakten, via index reminders_contact_id.
 * Utan contact_id söks e-post och telefon i alla listor, även klara rader.
 * Träffar som tillhör olika kontakter slås inte ihop. Utan flaggan gäller kontakt, e-post eller telefon.
 * Öppen rad vinner över en klar. Annars den äldsta. Befintlig contact_id skrivs inte om.
 *
 * @param array<string, mixed> $details
 */
function jeab_reminder_find_customer_product(JeabStore $store, string $title, array $details): ?array
{
    if (!function_exists('jeab_inkorg_reminder_offer')) {
        return null;
    }
    $offer = jeab_inkorg_reminder_offer(['title' => $title, 'tags' => $details['tags'] ?? []]);
    if ($offer === '') {
        return null;
    }
    $wantContact = trim((string) ($details['contact_id'] ?? ''));
    $sole = function_exists('jeab_contacts_sole') && jeab_contacts_sole();
    if ($sole && $wantContact !== '') {
        $best = null;
        foreach (jeab_reminder_rows_for_contact_product($store, $wantContact, $offer) as $row) {
            if (!is_array($row) || jeab_reminder_is_trashed($row) || (int) ($row['id'] ?? 0) < 1) {
                continue;
            }
            if (trim((string) ($row['contact_id'] ?? '')) !== $wantContact) {
                continue;
            }
            if (jeab_inkorg_reminder_offer($row) !== $offer) {
                continue;
            }
            $best = jeab_reminder_prefer_customer_product($best, $row);
        }

        return $best;
    }
    $wantEmail = mb_strtolower(trim((string) ($details['email'] ?? '')));
    if ($wantEmail !== '' && function_exists('jeab_follow_internal_address') && jeab_follow_internal_address($wantEmail)) {
        $wantEmail = '';
    }
    $wantPhone = jeab_reminder_phone_key((string) ($details['phone'] ?? ''));
    if ($wantEmail === '' && $wantPhone === '' && $wantContact === '') {
        return null;
    }
    $GLOBALS['_JEAB_REMINDER_PRODUCT_SCAN'] = (int) ($GLOBALS['_JEAB_REMINDER_PRODUCT_SCAN'] ?? 0) + 1;
    $best = null;
    $candidates = [];
    $seenContacts = [];
    foreach (jeab_reminders_every($store) as $row) {
        if (!is_array($row) || jeab_reminder_is_trashed($row) || (int) ($row['id'] ?? 0) < 1) {
            continue;
        }
        if (jeab_inkorg_reminder_offer($row) !== $offer) {
            continue;
        }
        $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
        $phone = jeab_reminder_phone_key((string) ($row['phone'] ?? ''));
        $contact = trim((string) ($row['contact_id'] ?? ''));
        $byContact = $wantContact !== '' && $contact !== '' && $contact === $wantContact;
        $byEmail = $wantEmail !== '' && $email !== '' && $email === $wantEmail;
        $byPhone = $wantPhone !== '' && $phone !== '' && $phone === $wantPhone;
        if ($byPhone && !$byEmail && !$byContact && function_exists('jeab_inkorg_party_names_agree')) {
            $wantName = (string) ($details['customer_name'] ?? '');
            $rowName = (string) ($row['customer_name'] ?? '');
            if (!jeab_inkorg_party_names_agree($wantName, $rowName)) {
                $byPhone = false;
            }
        }
        if (!($byContact || $byEmail || $byPhone)) {
            continue;
        }
        // E-post och telefon får inte slå ihop två olika kontakter.
        if ($sole && $contact !== '') {
            $seenContacts[$contact] = true;
        }
        $candidates[] = $row;
    }
    if ($sole && $wantContact === '' && count($seenContacts) > 1) {
        $candidates = array_values(array_filter(
            $candidates,
            static fn (array $row): bool => trim((string) ($row['contact_id'] ?? '')) === ''
        ));
    }
    foreach ($candidates as $row) {
        $best = jeab_reminder_prefer_customer_product($best, $row);
    }

    return $best;
}

function jeab_reminder_thread_from_text(string $text): string
{
    if (preg_match('/(?:^|[?&])id=([^&\s]+)/', $text, $m) !== 1) {
        return '';
    }
    return rawurldecode((string) $m[1]);
}

/** Påminnelsen som bara säger att kontakten måste läggas in för hand. */
function jeab_reminder_is_contact_fail(array $row): bool
{
    $title = (string) ($row['title'] ?? '');
    $note = (string) ($row['note'] ?? '');

    return str_contains($title, 'Kontakt kunde inte skapas')
        || str_contains($note, 'måste läggas till manuellt i Kontakter');
}

/** Senaste påminnelsen som pekar på ett inkorgsärende. */
function jeab_reminder_for_thread(string $tid): ?array
{
    $tid = trim($tid);
    if ($tid === '' || !function_exists('jeab_store')) {
        return null;
    }
    $store = jeab_store();
    if (!$store instanceof JeabStore) {
        return null;
    }
    $pick = static function (array $row) use ($tid): bool {
        if (trim((string) ($row['thread_id'] ?? '')) !== $tid) {
            return false;
        }
        if (jeab_reminder_is_contact_fail($row)) {
            return false;
        }

        return (int) ($row['id'] ?? 0) > 0;
    };
    if ($store instanceof JeabPdoStore) {
        $stmt = $store->pdo()->prepare(
            'SELECT * FROM reminders WHERE thread_id = ? AND id > 0 ORDER BY id DESC'
        );
        $stmt->execute([$tid]);
        $rows = $stmt->fetchAll();
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hydrated = jeab_hydrate_reminder($row);
            if ($pick($hydrated)) {
                return $hydrated;
            }
        }

        return null;
    }
    $best = null;
    foreach ($store->listReminders(true) as $row) {
        if (!is_array($row) || !$pick($row)) {
            continue;
        }
        if ($best === null || (int) ($row['id'] ?? 0) > (int) ($best['id'] ?? 0)) {
            $best = $row;
        }
    }

    return $best;
}

function jeab_reminder_remember_thread(JeabStore $store, int $id, string $tid): void
{
    if ($id < 1 || $tid === '') {
        return;
    }
    $row = $store->getReminder($id);
    if (!is_array($row) || trim((string) ($row['thread_id'] ?? '')) !== '') {
        return;
    }
    try {
        $store->saveReminder($id, ['thread_id' => $tid]);
    } catch (Throwable $e) {
        unset($e);
        return;
    }
    if (function_exists('jeab_inkorg_close_open_for_reminder')) {
        $fresh = $store->getReminder($id);
        if (is_array($fresh)) {
            try {
                jeab_inkorg_close_open_for_reminder($fresh, true);
            } catch (Throwable $e) {
                unset($e);
            }
        }
    }
}

function jeab_reminder_require_actor(array $row): void
{
    if (!function_exists('jeab_company_allowed')) {
        return;
    }
    if (!jeab_company_allowed(jeab_reminder_company($row))) {
        throw new InvalidArgumentException('Inte behörig.');
    }
}

function jeab_get_reminder(JeabStore $store, int $id): ?array
{
    $row = $store->getReminder($id);
    if (!is_array($row)) {
        return null;
    }
    if (function_exists('jeab_company_allowed') && !jeab_company_allowed(jeab_reminder_company($row))) {
        return null;
    }

    return $row;
}

function jeab_parse_tags(mixed $raw): array
{
    $names = [];
    if (is_array($raw)) {
        foreach ($raw as $item) {
            if (is_array($item)) {
                $names = array_merge($names, jeab_parse_tags($item));
                continue;
            }
            $one = jeab_normalize_tag((string) $item);
            if ($one !== null) {
                $names[] = $one;
            }
        }
    } else {
        $text = trim((string) $raw);
        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return jeab_parse_tags($decoded);
            }
            foreach (preg_split('/[\s,]+/', $text) ?: [] as $item) {
                $one = jeab_normalize_tag($item);
                if ($one !== null) {
                    $names[] = $one;
                }
            }
        }
    }
    $out = [];
    foreach ($names as $name) {
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (!isset($out[$key])) {
            $out[$key] = $name;
        }
    }
    return array_values($out);
}

function jeab_reminder_tag_hidden(string $tag): bool
{
    $raw = ltrim(trim($tag), '#');
    $raw = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    $raw = strtr($raw, ['ä' => 'a', 'ö' => 'o', 'å' => 'a', 'Ä' => 'a', 'Ö' => 'o', 'Å' => 'a']);

    return in_array($raw, ['slapp', 'forlorad'], true);
}

/** Taggar som visas som knappar, filter och på kort. Dolda taggar får ligga kvar på raden. */
function jeab_reminder_tag_choices(): array
{
    return array_values(array_filter(
        JEAB_REMINDER_TAGS,
        static fn (string $tag): bool => !jeab_reminder_tag_hidden($tag)
    ));
}

function jeab_normalize_tag(string $raw): ?string
{
    $raw = trim($raw);
    $raw = ltrim($raw, '#');
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (strcasecmp($raw, 'test') === 0) {
        return 'test';
    }
    foreach (JEAB_REMINDER_TAGS as $allowed) {
        if (strcasecmp($raw, $allowed) === 0) {
            return $allowed;
        }
    }
    return null;
}

/** Test-chip på påminnelsen. Syns när taggen test ligger på raden. */
function jeab_reminder_test_chip_html(array $row): string
{
    if (!in_array('test', jeab_parse_tags($row['tags'] ?? []), true)) {
        return '';
    }

    return '<span class="tag-chip is-on is-test">Test</span>';
}

function jeab_tag_catalog(?JeabStore $store = null): array
{
    return JEAB_REMINDER_TAGS;
}

function jeab_compose_notes(array $parts): ?string
{
    $lines = [];
    $seen = [];
    foreach ($parts as $part) {
        $text = trim((string) ($part ?? ''));
        if ($text === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $lines[] = $text;
    }
    return $lines !== [] ? implode("\n", $lines) : null;
}

function jeab_reminder_notes_text(array $row): string
{
    return trim((string) ($row['note'] ?? $row['notes'] ?? ''));
}

function jeab_reminder_display_note(array $row): string
{
    $note = jeab_reminder_notes_text($row);
    if ($note === '') {
        return '';
    }
    $view = jeab_reminder_shown_customer($row);
    $phone = $view['phone'];
    $email = $view['email'];
    if ($phone !== '') {
        $want = jeab_phone_href_number($phone);
        foreach (jeab_find_phones($note) as $found) {
            if (jeab_phone_href_number($found) === $want) {
                $note = str_replace($found, '', $note);
            }
        }
        $note = str_replace($phone, '', $note);
    }
    if ($email !== '') {
        foreach (jeab_find_emails($note) as $found) {
            if (strcasecmp($found, $email) === 0) {
                $note = str_replace($found, '', $note);
            }
        }
    }
    $note = preg_replace("/[ \t]+/u", ' ', $note) ?? $note;
    $note = preg_replace("/\n[ \t]*\n+/", "\n", $note) ?? $note;
    return trim($note);
}

function jeab_reminder_tap_keys(): array
{
    return ['ringt', 'pratat', 'mailat', 'smsat'];
}

function jeab_reminder_tap_int(mixed $raw): int
{
    $n = (int) $raw;
    return $n < 0 ? 0 : $n;
}

function jeab_reminder_tap_counts(array $row): array
{
    $out = [];
    foreach (jeab_reminder_tap_keys() as $key) {
        $out[$key] = jeab_reminder_tap_int($row[$key] ?? 0);
    }
    return $out;
}

function jeab_reminder_tap_total(array $row): int
{
    return array_sum(jeab_reminder_tap_counts($row));
}

/** Synlig siffra är summan. Etiketten säger «mail» bara när alla tryck är mail. */
function jeab_reminder_tap_total_label(array $row): string
{
    $taps = jeab_reminder_tap_counts($row);
    $total = array_sum($taps);
    if ($total <= 0) {
        return '';
    }
    if ((int) $taps['mailat'] === $total) {
        return $total . ' mail';
    }
    $words = ['ringt' => 'ringda', 'pratat' => 'samtal', 'mailat' => 'mail', 'smsat' => 'sms'];
    $parts = [];
    foreach ($words as $key => $word) {
        $n = (int) ($taps[$key] ?? 0);
        if ($n > 0) {
            $parts[] = $n . ' ' . $word;
        }
    }
    return implode(', ', $parts);
}

function jeab_reminder_trim_sep(string $text): string
{
    $text = trim($text);
    $text = preg_replace('/^(?:\s*·\s*)+|(?:\s*·\s*)+$/u', '', $text) ?? $text;
    return trim($text);
}

/** Tröskel i millisekunder. `JEAB_REMINDER_SLOW_MS` eller `_JEAB_REMINDER_SLOW_MS`, annars 2000. */
function jeab_reminder_slow_threshold_ms(): int
{
    $raw = $GLOBALS['_JEAB_REMINDER_SLOW_MS'] ?? null;
    if ($raw === null) {
        $env = getenv('JEAB_REMINDER_SLOW_MS');
        $raw = $env === false ? null : $env;
    }
    if (is_int($raw) || (is_string($raw) && preg_match('/\A\d+\z/', $raw) === 1)) {
        return max(1, (int) $raw);
    }
    return 2000;
}

/** Sekunder som samma rutt, anrop och sökning delar en felloggrad. Standard 30 minuter. */
function jeab_reminder_slow_window_seconds(): int
{
    $raw = $GLOBALS['_JEAB_REMINDER_SLOW_WINDOW'] ?? null;
    if ($raw === null) {
        $env = getenv('JEAB_REMINDER_SLOW_WINDOW');
        $raw = $env === false ? null : $env;
    }
    if (is_int($raw) || (is_string($raw) && preg_match('/\A\d+\z/', $raw) === 1)) {
        return max(1, (int) $raw);
    }
    return 30 * 60;
}

function jeab_reminder_slow_route(): string
{
    $page = strtolower(trim((string) ($_GET['p'] ?? 'reminders')));
    $page = preg_replace('/[^a-z0-9_-]/', '', $page) ?? '';
    if ($page === '') {
        $page = 'reminders';
    }
    $intent = strtolower(trim((string) ($_GET['intent'] ?? '')));
    $intent = preg_replace('/[^a-z0-9_-]/', '', $intent) ?? '';
    return $intent === '' ? $page : $page . '/' . $intent;
}

/** Samma sökning oavsett skiftläge och extra mellanslag. Texten loggas inte. */
function jeab_reminder_slow_norm_query(): string
{
    $q = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
    $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
    $q = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    if (function_exists('mb_substr')) {
        $q = mb_substr($q, 0, 160, 'UTF-8');
    } else {
        $q = substr($q, 0, 160);
    }
    return trim($q);
}

function jeab_reminder_slow_fingerprint(string $what, ?string $route = null, ?string $query = null): string
{
    $what = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $what) ?? '');
    if ($what === '') {
        $what = 'reminders';
    }
    $route = $route ?? jeab_reminder_slow_route();
    $query = $query ?? jeab_reminder_slow_norm_query();
    return hash('sha256', $route . '|' . $what . '|' . $query);
}

/** Långsamma påminnelseanrop loggas utan kundtext. Under tröskeln skrivs inget. */
function jeab_reminder_log_slow(string $what, float $seconds): void
{
    $what = preg_replace('/[^a-z0-9_-]/i', '', $what) ?? '';
    if ($what === '') {
        $what = 'reminders';
    }
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span($what, $seconds);
    }
    $ms = (int) round(max(0, $seconds) * 1000);
    if ($ms < jeab_reminder_slow_threshold_ms() || !function_exists('jeab_log_app_error')) {
        return;
    }
    jeab_log_app_error('reminders', 'långsamt anrop ' . $what . ' ' . $ms . ' ms', [
        'fingerprint' => jeab_reminder_slow_fingerprint($what),
        'dedupe_seconds' => jeab_reminder_slow_window_seconds(),
    ]);
}

/**
 * Det som hänger på #vunnen: orderunderlag, Lantmäteriet och Malmö-bokning.
 *
 * @return list<string>
 */
function jeab_reminder_vunnen_related_bits(JeabStore $store, array $row): array
{
    unset($store);
    $id = (int) ($row['id'] ?? 0);
    $bits = [];
    $purchase = ($id > 0 && function_exists('jeab_purchase_get')) ? jeab_purchase_get($id) : null;
    if (is_array($purchase)) {
        $order = trim((string) ($purchase['fortnox_order'] ?? ''));
        $bits[] = $order !== '' ? 'Fortnox-order ' . $order : 'orderunderlag';
        if (trim((string) ($purchase['fastighetsbeteckning'] ?? '')) !== '') {
            $bits[] = 'Lantmäteriet-uppgifter';
        }
        $booked = (int) ($purchase['booking_id'] ?? 0);
        if ($booked > 0 && jeab_reminder_company($row) === 'malmo') {
            $bits[] = 'Malmö-bokningen';
        }
    }
    $ownBooking = (int) ($row['booking_id'] ?? 0);
    if ($ownBooking > 0 && jeab_reminder_company($row) === 'malmo' && !in_array('Malmö-bokningen', $bits, true)) {
        $bits[] = 'Malmö-bokningen';
    }

    return $bits;
}

/**
 * #vunnen av och användaren behåller ordern: texten bara visar vad som står kvar.
 * Ingenting raderas på den vägen.
 */
function jeab_reminder_vunnen_untag_notice(JeabStore $store, array $row): string
{
    $bits = jeab_reminder_vunnen_related_bits($store, $row);
    if ($bits === []) {
        return '';
    }

    return '#vunnen är bort. Kvar finns ' . implode(', ', $bits) . '. Inget av det tas bort.';
}

/**
 * Användaren valde att ta bort ordern när #vunnen tas av.
 * Ordern tombstonas som vanligt Radera. Bokningar avbokas så tiden släpps.
 * Lantmäteriet-uppgifterna ligger på ordern och följer med när den tas bort.
 *
 * @return array{order:bool,bookings:int}
 */
function jeab_reminder_vunnen_drop_related(JeabStore $store, array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $purchase = ($id > 0 && function_exists('jeab_purchase_get')) ? jeab_purchase_get($id) : null;
    $bookingIds = [];
    $ownBooking = (int) ($row['booking_id'] ?? 0);
    if ($ownBooking > 0) {
        $bookingIds[$ownBooking] = $ownBooking;
    }
    if (is_array($purchase)) {
        $booked = (int) ($purchase['booking_id'] ?? 0);
        if ($booked > 0) {
            $bookingIds[$booked] = $booked;
        }
    }
    $cancelled = 0;
    foreach ($bookingIds as $bookingId) {
        try {
            $booking = method_exists($store, 'getBooking') ? $store->getBooking($bookingId) : null;
            if (!is_array($booking) || (string) ($booking['status'] ?? '') === 'cancelled') {
                continue;
            }
            if (function_exists('jeab_cancel_booking')) {
                jeab_cancel_booking($store, $bookingId, 'web', ['from' => 'vunnen']);
                $cancelled++;
            }
        } catch (Throwable) {
        }
    }
    $removedOrder = false;
    if (is_array($purchase) && $id > 0 && function_exists('jeab_ordrar_delete')) {
        try {
            jeab_ordrar_delete(['id' => $id], $store);
            $removedOrder = !is_array(jeab_purchase_get($id));
        } catch (Throwable) {
            $removedOrder = function_exists('jeab_purchase_get') && !is_array(jeab_purchase_get($id));
        }
    }

    return ['order' => $removedOrder, 'bookings' => $cancelled];
}

function jeab_reminder_vunnen_drop_message(array $result): string
{
    $order = !empty($result['order']);
    $bookings = (int) ($result['bookings'] ?? 0) > 0;
    if ($order && $bookings) {
        return 'Taggen är bort. Ordern, Lantmäteriet-uppgifterna och bokningen är borttagna.';
    }
    if ($order) {
        return 'Taggen är bort. Ordern och Lantmäteriet-uppgifterna är borttagna.';
    }
    if ($bookings) {
        return 'Taggen är bort. Bokningen är avbokad.';
    }

    return 'Taggen är bort. Det fanns ingen order eller bokning att ta bort.';
}

/** Svep-stäng av ett lead. Hoppar över ärenden som kroken inte skrivit än. Inget kundutskick. */
function jeab_reminder_close_linked_lead(array $row): void
{
    if (function_exists('jeab_inkorg_close_open_for_reminder')) {
        try {
            jeab_inkorg_close_open_for_reminder($row, true);
        } catch (Throwable) {
        }
    }
    $tid = trim((string) ($row['thread_id'] ?? ''));
    if ($tid === '' || !function_exists('jeab_inkorg_mark_hook_closed')) {
        return;
    }
    if (function_exists('jeab_inkorg_open_thread_awaits_lead_hook') && jeab_inkorg_open_thread_awaits_lead_hook($tid)) {
        return;
    }
    if (function_exists('jeab_malmo_hs_kim_thread') && jeab_malmo_hs_kim_thread($tid)) {
        return;
    }
    $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
    if (!is_array($meta)) {
        $meta = [];
    }
    if (trim((string) ($meta['reopened_at'] ?? '')) !== '' || trim((string) ($meta['hook_reopened_at'] ?? '')) !== '') {
        return;
    }
    try {
        jeab_inkorg_mark_hook_closed($tid, 'reminder');
    } catch (Throwable) {
    }
}

function jeab_reminder_strip_postnummer(string $text): string
{
    $text = preg_replace('/\b\d{3}\s?\d{2}\b/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s*,\s*,+/u', ',', $text) ?? $text;
    return trim($text, " \t\n\r\0\x0B,");
}

function jeab_reminder_address_source(array $row): string
{
    $address = trim((string) ($row['address'] ?? ''));
    if ($address !== '') {
        return $address;
    }
    $title = trim((string) ($row['title'] ?? ''));
    if ($title === '') {
        return '';
    }
    if (function_exists('jeab_ordrar_looks_like_street') && jeab_ordrar_looks_like_street($title)) {
        return $title;
    }
    if (preg_match('/\d/u', $title) && !preg_match('/^\+?\d[\d\s-]{5,}$/u', $title)) {
        return $title;
    }
    return '';
}

function jeab_reminder_street(string $address): string
{
    $address = jeab_reminder_strip_postnummer($address);
    if ($address === '') {
        return '';
    }
    if (str_contains($address, ',')) {
        return trim((string) explode(',', $address, 2)[0]);
    }
    if (preg_match('/^(.+?\s+\d+\w*)\s+(\p{Lu}[\p{L}-]+(?:\s+\p{Lu}[\p{L}-]+)?)$/u', $address, $m)) {
        return trim($m[1]);
    }
    return $address;
}

/** Ort med versal, utan «Sverige». Känd ort från kontakter vinner. */
function jeab_address_town(string $town): string
{
    $town = function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name($town) : trim($town);
    $town = trim($town, " ,");
    if ($town === '') {
        return '';
    }
    if (function_exists('jeab_kontakt_place_canon')) {
        $canon = jeab_kontakt_place_canon($town);
        if ($canon !== '') {
            return $canon;
        }
    }
    return mb_convert_case($town, MB_CASE_TITLE, 'UTF-8');
}

/**
 * Gata, postnummer och ort. Visningen är «Gata nr, Ort» utan land och utan postnummer.
 *
 * @return array{street:string,postal:string,city:string,display:string}
 */
function jeab_address_parts(string $raw): array
{
    $empty = ['street' => '', 'postal' => '', 'city' => '', 'display' => ''];
    $raw = function_exists('jeab_address_repair_number') ? jeab_address_repair_number($raw) : trim($raw);
    if ($raw === '') {
        return $empty;
    }
    $parsed = function_exists('jeab_kontakt_parse_address')
        ? jeab_kontakt_parse_address($raw)
        : ['street' => $raw, 'postal' => '', 'city' => ''];
    $city = jeab_address_town((string) ($parsed['city'] ?? ''));
    $street = trim((string) ($parsed['street'] ?? ''));
    $street = preg_replace('/\s*,?\s*(?:sverige|sweden|suede|suède)\s*$/iu', '', $street) ?? $street;
    $street = trim($street, " ,");
    if ($city !== '' && preg_match('/,\s*' . preg_quote($city, '/') . '\s*$/iu', $street) === 1) {
        $street = trim((string) preg_replace('/,\s*' . preg_quote($city, '/') . '\s*$/iu', '', $street), " ,");
    }
    $display = $street;
    if ($city !== '') {
        $display = $street === '' ? $city : $street . ', ' . $city;
    }
    return [
        'street' => $street,
        'postal' => (string) ($parsed['postal'] ?? ''),
        'city' => $city,
        'display' => $display,
    ];
}

function jeab_reminder_parse_city(string $address): string
{
    $clean = jeab_reminder_strip_postnummer($address);
    if ($clean === '') {
        return '';
    }
    $street = jeab_reminder_street($address);
    if (str_contains($clean, ',')) {
        $parts = array_values(array_filter(array_map('trim', explode(',', $clean)), static fn(string $p): bool => $p !== ''));
        $last = $parts !== [] ? (string) $parts[count($parts) - 1] : '';
        if ($last !== '' && $last !== $street && !preg_match('/\d/u', $last)) {
            return $last;
        }
    }
    if (preg_match('/^.+?\s+\d+\w*\s+(\p{Lu}[\p{L}-]+(?:\s+\p{Lu}[\p{L}-]+)?)$/u', $clean, $m)) {
        $city = trim($m[1]);
        if ($city !== '' && $city !== $street) {
            return $city;
        }
    }
    return '';
}

function jeab_reminder_lookup_city(string $query, array $row = []): string
{
    $query = trim($query);
    if ($query === '') {
        return '';
    }
    $stub = $GLOBALS['_JEAB_REMINDER_CITY_LOOKUP'] ?? null;
    if (is_callable($stub)) {
        return trim((string) $stub($query));
    }
    if (function_exists('jeab_address_extract')) {
        $parts = function_exists('jeab_address_parts') ? jeab_address_parts($query) : ['street' => $query, 'city' => '', 'postal' => ''];
        $company = function_exists('jeab_reminder_company') ? jeab_reminder_company($row) : '';
        $started = microtime(true);
        $result = jeab_address_extract([
            'company' => $company,
            'street' => (string) ($parts['street'] ?? '') !== '' ? (string) $parts['street'] : $query,
            'city' => (string) ($parts['city'] ?? ''),
            'postal' => (string) ($parts['postal'] ?? '') !== '' ? (string) $parts['postal'] : (string) ($row['postal'] ?? ''),
            'name' => (string) ($row['customer_name'] ?? ''),
            'thread_id' => (string) ($row['thread_id'] ?? ''),
        ]);
        jeab_reminder_log_slow('ort', microtime(true) - $started);
        return (string) ($result['city'] ?? '');
    }
    if (!function_exists('jeab_ordrar_address_lookup')) {
        return '';
    }
    try {
        $found = jeab_ordrar_address_lookup($query);
    } catch (Throwable) {
        return '';
    }
    return jeab_reminder_parse_city((string) ($found['address'] ?? ''));
}

function jeab_reminder_resolve_city(array $row, bool $lookup = true): string
{
    $source = jeab_reminder_address_source($row);
    $parsed = jeab_reminder_parse_city($source);
    $company = function_exists('jeab_reminder_company') ? jeab_reminder_company($row) : '';
    if ($parsed !== '' && $company !== '' && function_exists('jeab_address_city_kind') && jeab_address_city_kind($company, $parsed) === 'outside') {
        $parsed = '';
    }
    if ($parsed !== '') {
        return $parsed;
    }
    $saved = array_key_exists('ort', $row) && $row['ort'] !== null ? trim((string) $row['ort']) : '';
    if ($saved !== '' && !($company !== '' && function_exists('jeab_address_city_kind') && jeab_address_city_kind($company, $saved) === 'outside')) {
        return $saved;
    }
    if (!$lookup || $source === '') {
        return '';
    }
    $city = jeab_reminder_lookup_city($source, $row);
    if ($city !== '') {
        return $city;
    }
    $name = trim((string) ($row['customer_name'] ?? ''));
    if ($name !== '') {
        return jeab_reminder_lookup_city(trim($name . ' ' . $source), $row);
    }
    return '';
}

/**
 * Ort vid sparning. Adressen och den sparade cachen räcker när de träffar.
 * Ett nätuppslag (ArcGIS, grok, Lantmäteriet) väntar inte på svaret: det körs
 * efter fastcgi_finish_request, annars av tickens jeab_reminders_tick_one_ort.
 * En redan sparad ort skrivs inte över med tomt när uppslaget skjuts upp.
 */
function jeab_reminder_city_for_save(array $probe): string
{
    $city = jeab_reminder_resolve_city($probe, false);
    if ($city !== '') {
        return $city;
    }
    $cached = jeab_reminder_ort_cache_get(jeab_reminder_ort_cache_key($probe));
    if ($cached !== null) {
        return $cached;
    }
    $stub = $GLOBALS['_JEAB_REMINDER_CITY_LOOKUP'] ?? null;
    $network = function_exists('jeab_address_extract_network') && jeab_address_extract_network();
    if (is_callable($stub) || !$network) {
        return jeab_reminder_resolve_city($probe, true);
    }
    jeab_reminder_ort_after_response((int) ($probe['id'] ?? 0), $probe);

    return '';
}

/**
 * Ett uppslag efter att klienten fått svaret. Utan fastcgi_finish_request
 * lämnas raden, och tick fyller en saknad ort per varv.
 */
function jeab_reminder_ort_after_response(int $id, array $probe): void
{
    if ($id <= 0) {
        return;
    }
    $GLOBALS['_JEAB_ORT_AFTER'] = ['id' => $id, 'probe' => $probe];
    if (!empty($GLOBALS['_JEAB_ORT_AFTER_ARMED'])) {
        return;
    }
    $GLOBALS['_JEAB_ORT_AFTER_ARMED'] = true;
    register_shutdown_function(static function (): void {
        $job = $GLOBALS['_JEAB_ORT_AFTER'] ?? null;
        unset($GLOBALS['_JEAB_ORT_AFTER']);
        if (!is_array($job)) {
            return;
        }
        $flushed = function_exists('fastcgi_finish_request') && @fastcgi_finish_request();
        if (!$flushed) {
            return;
        }
        if (function_exists('jeab_release_session')) {
            jeab_release_session();
        }
        $id = (int) ($job['id'] ?? 0);
        $probe = is_array($job['probe'] ?? null) ? $job['probe'] : [];
        if ($id <= 0) {
            return;
        }
        try {
            $prev = jeab_reminder_ort_lookup_budget();
            jeab_reminder_ort_lookup_budget(1);
            $city = jeab_reminder_cached_city($probe);
            jeab_reminder_ort_lookup_budget($prev);
            if ($city === '') {
                return;
            }
            $store = jeab_store();
            $fresh = $store->getReminder($id);
            if (!is_array($fresh) || trim((string) ($fresh['ort'] ?? '')) !== '') {
                return;
            }
            $fields = [
                'ort' => $city,
                'updated_at' => (string) ($fresh['updated_at'] ?? jeab_now()),
            ];
            $last = $GLOBALS['_JEAB_ADDRESS_EXTRACT_LAST'] ?? null;
            if (is_array($last) && trim((string) ($fresh['postal'] ?? '')) === '' && (string) ($last['postal'] ?? '') !== '' && (string) ($last['city'] ?? '') === $city) {
                $fields['postal'] = (string) $last['postal'];
            }
            $store->saveReminder($id, $fields);
        } catch (Throwable) {
        }
    });
}

function jeab_reminder_ensure_ort(JeabStore $store, array $row): array
{
    $source = jeab_reminder_address_source($row);
    $parsed = jeab_reminder_parse_city($source);
    $company = function_exists('jeab_reminder_company') ? jeab_reminder_company($row) : '';
    if ($parsed !== '' && $company !== '' && function_exists('jeab_address_city_kind') && jeab_address_city_kind($company, $parsed) === 'outside') {
        $parsed = '';
    }
    $hasOrt = array_key_exists('ort', $row) && $row['ort'] !== null;
    $saved = $hasOrt ? trim((string) $row['ort']) : null;
    if ($saved !== null && $saved !== '' && $company !== '' && function_exists('jeab_address_city_kind') && jeab_address_city_kind($company, $saved) === 'outside') {
        $saved = null;
        $hasOrt = false;
    }
    $city = $parsed !== '' ? $parsed : (string) ($saved ?? '');
    if ($parsed === '' && $saved === null && $source !== '') {
        $city = jeab_reminder_cached_city($row);
    }
    if ($hasOrt && $saved === $city) {
        $row['ort'] = $city;
        return $row;
    }
    $row['ort'] = $city;
    $last = $GLOBALS['_JEAB_ADDRESS_EXTRACT_LAST'] ?? null;
    if (is_array($last) && trim((string) ($row['postal'] ?? '')) === '' && (string) ($last['postal'] ?? '') !== '' && (string) ($last['city'] ?? '') === $city) {
        $row['postal'] = (string) $last['postal'];
    }
    return $row;
}

/**
 * Listan och dess ajax ska inte skanna ort_-nycklar. Tick får göra det.
 */
function jeab_reminder_ort_skip_key_scan(): bool
{
    if (!empty($GLOBALS['_JEAB_TICK'])) {
        return false;
    }
    if (!empty($GLOBALS['_JEAB_PAGE_REQUEST'])) {
        return true;
    }
    $intent = strtolower(trim((string) ($_GET['intent'] ?? '')));

    return in_array($intent, ['sync', 'more', 'page', 'pulse'], true);
}

function jeab_reminder_ort_cache_path(): string
{
    return jeab_data_dir() . '/reminder-ort-cache.json';
}

function jeab_reminder_ort_cache_key(array $row): string
{
    $source = jeab_reminder_address_source($row);
    $name = trim((string) ($row['customer_name'] ?? ''));
    $raw = $source . '|' . $name;
    $raw = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    return sha1($raw);
}

/**
 * Sparade ortuppslag (adress → ort). Tom sträng = hittades inte.
 *
 * @return array<string, array{c:string,t:int}>
 */
function jeab_reminder_ort_cache_read(): array
{
    if (isset($GLOBALS['_JEAB_REMINDER_ORT_CACHE']) && is_array($GLOBALS['_JEAB_REMINDER_ORT_CACHE'])) {
        return $GLOBALS['_JEAB_REMINDER_ORT_CACHE'];
    }
    $data = [];
    if (!function_exists('jeab_biz_doc_load')) {
        require_once __DIR__ . '/biz_doc_store.php';
    }
    if (function_exists('jeab_biz_doc_use_sql') && jeab_biz_doc_use_sql('reminder_ort_cache')) {
        $decoded = jeab_biz_doc_load('reminder_ort_cache');
    } else {
        $path = jeab_reminder_ort_cache_path();
        $decoded = null;
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        }
    }
    if (is_array($decoded)) {
        foreach ($decoded as $key => $item) {
            if (is_string($key) && is_array($item) && isset($item['c'], $item['t'])) {
                $data[$key] = ['c' => (string) $item['c'], 't' => (int) $item['t']];
            }
        }
    }
    // Sidvisning och listans ajax (sync, more, page, pulse) läser det samlade
    // dokumentet. LIKE över alla ort_-rader hör hemma på tick, inte på en poll (B-023).
    // Uppslagsbudget 0 ska inte skanna ort_-nycklarna den heller.
    $ortBudget = (int) ($GLOBALS['_JEAB_REMINDER_ORT_BUDGET'] ?? JEAB_REMINDER_ORT_LOOKUPS_PER_REQUEST);
    if (!jeab_reminder_ort_skip_key_scan() && $ortBudget > 0 && function_exists('jeab_biz_doc_use_sql') && jeab_biz_doc_use_sql('reminder_ort_cache') && function_exists('jeab_biz_doc_store_pdo')) {
        $GLOBALS['_JEAB_REMINDER_ORT_LIKE'] = (int) ($GLOBALS['_JEAB_REMINDER_ORT_LIKE'] ?? 0) + 1;
        $pdo = jeab_biz_doc_store_pdo();
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->query("SELECT doc_key, payload FROM json_docs WHERE doc_key LIKE 'ort\\_%' ESCAPE '\\'");
                foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $docKey = (string) ($row['doc_key'] ?? '');
                    if (!str_starts_with($docKey, 'ort_')) {
                        continue;
                    }
                    $item = json_decode((string) ($row['payload'] ?? ''), true);
                    $cacheKey = substr($docKey, 4);
                    if ($cacheKey !== '' && is_array($item) && isset($item['c'], $item['t'])) {
                        $data[$cacheKey] = ['c' => (string) $item['c'], 't' => (int) $item['t']];
                    }
                }
            } catch (Throwable) {
                // Hela dokumentet ovan räcker om per-nyckel-raderna saknas.
            }
        }
    }
    $GLOBALS['_JEAB_REMINDER_ORT_CACHE'] = $data;
    return $data;
}

/** null = okänt eller för gammalt, '' = hittades inte nyligen. */
function jeab_reminder_ort_cache_get(string $key, ?int $now = null): ?string
{
    $cache = jeab_reminder_ort_cache_read();
    if (!isset($cache[$key])) {
        return null;
    }
    $item = $cache[$key];
    if ($item['c'] !== '') {
        return $item['c'];
    }
    $now ??= time();
    return ($now - (int) $item['t']) < JEAB_REMINDER_ORT_MISS_TTL ? '' : null;
}

function jeab_reminder_ort_cache_put(string $key, string $city, ?int $now = null): void
{
    $cache = jeab_reminder_ort_cache_read();
    $cache[$key] = ['c' => trim($city), 't' => $now ?? time()];
    if (count($cache) > 2000) {
        uasort($cache, static fn(array $a, array $b): int => $b['t'] <=> $a['t']);
        $cache = array_slice($cache, 0, 2000, true);
    }
    $GLOBALS['_JEAB_REMINDER_ORT_CACHE'] = $cache;
    // Sidvisning skriver inte. Tick och övriga anrop skriver en nyckel, inte hela dokumentet om SQL är på.
    if (!empty($GLOBALS['_JEAB_PAGE_REQUEST']) && empty($GLOBALS['_JEAB_TICK'])) {
        return;
    }
    if (!jeab_private_data_writable()) {
        return;
    }
    if (!function_exists('jeab_biz_doc_save')) {
        require_once __DIR__ . '/biz_doc_store.php';
    }
    if (function_exists('jeab_biz_doc_use_sql') && jeab_biz_doc_use_sql('reminder_ort_cache')) {
        $item = $cache[$key] ?? null;
        $pdo = function_exists('jeab_biz_doc_store_pdo') ? jeab_biz_doc_store_pdo() : null;
        if (is_array($item) && $pdo instanceof PDO && function_exists('jeab_sql_doc_put') && function_exists('jeab_biz_doc_store_ensure')) {
            jeab_biz_doc_store_ensure($pdo);
            jeab_sql_doc_put($pdo, 'ort_' . $key, $item);
        }
        return;
    }
    // data/reminder-ort-cache.json är ortcachen (biz_docs), inte ett tillfälligt lager.
    // .tmp finns bara för att byta ut den filen atomiskt. När LEGACY_JSON_WRITES är av
    // och biz_docs är SQL med grön verify skrivs filen inte. SQL-vägen ovan gäller annars.
    if (function_exists('jeab_legacy_json_skip') && jeab_legacy_json_skip('biz_docs')) {
        return;
    }
    $path = jeab_reminder_ort_cache_path();
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return;
    }
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
    }
}

function jeab_reminder_ort_lookup_budget(?int $reset = null): int
{
    if ($reset !== null) {
        $GLOBALS['_JEAB_REMINDER_ORT_BUDGET'] = $reset;
    }
    if (!array_key_exists('_JEAB_REMINDER_ORT_BUDGET', $GLOBALS)) {
        $GLOBALS['_JEAB_REMINDER_ORT_BUDGET'] = JEAB_REMINDER_ORT_LOOKUPS_PER_REQUEST;
    }
    return (int) $GLOBALS['_JEAB_REMINDER_ORT_BUDGET'];
}

/**
 * Ort för en rad utan sparad ort: läs först från cachen. Nätverksuppslag
 * (Lantmäteriet, ca 1 s styck) görs högst JEAB_REMINDER_ORT_LOOKUPS_PER_REQUEST
 * gånger per anrop och sparas, så listan inte slår upp samma adress varje gång.
 */
function jeab_reminder_cached_city(array $row): string
{
    $key = jeab_reminder_ort_cache_key($row);
    $cached = jeab_reminder_ort_cache_get($key);
    if ($cached !== null) {
        return $cached;
    }
    $budget = jeab_reminder_ort_lookup_budget();
    if ($budget <= 0) {
        return '';
    }
    jeab_reminder_ort_lookup_budget($budget - 1);
    $city = jeab_reminder_resolve_city($row, true);
    jeab_reminder_ort_cache_put($key, $city);
    return $city;
}

function jeab_reminders_ensure_ort(JeabStore $store, array $rows, bool $lookup = true): array
{
    $prev = jeab_reminder_ort_lookup_budget();
    if (!$lookup) {
        jeab_reminder_ort_lookup_budget(0);
    }
    try {
        $out = [];
        foreach ($rows as $row) {
            $out[] = is_array($row) ? jeab_reminder_ensure_ort($store, $row) : $row;
        }
        return $out;
    } finally {
        if (!$lookup) {
            jeab_reminder_ort_lookup_budget($prev);
        }
    }
}

/** En saknad ort, från cron/tick. Inte från sidvisningen. */
function jeab_reminders_tick_one_ort(JeabStore $store): void
{
    $rows = jeab_list_reminders($store, false, null, null);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $source = jeab_reminder_address_source($row);
        if ($source === '' || jeab_reminder_parse_city($source) !== '') {
            continue;
        }
        if (trim((string) ($row['ort'] ?? '')) !== '') {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $prev = jeab_reminder_ort_lookup_budget();
        jeab_reminder_ort_lookup_budget(1);
        $city = jeab_reminder_cached_city($row);
        jeab_reminder_ort_lookup_budget($prev);
        if ($city === '') {
            return;
        }
        $fresh = $store->getReminder($id);
        if (!is_array($fresh) || trim((string) ($fresh['ort'] ?? '')) !== '') {
            return;
        }
        $store->saveReminder($id, [
            'ort' => $city,
            'updated_at' => (string) ($fresh['updated_at'] ?? jeab_now()),
        ]);
        return;
    }
}

/** En saknad ort slås upp efter att sidan redan skickats, eller vid sparning. */
function jeab_reminders_defer_missing_ort(JeabStore $store, array $rows): void
{
    if (!function_exists('jeab_defer_after_response')) {
        return;
    }
    $pending = null;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $source = jeab_reminder_address_source($row);
        if ($source === '' || jeab_reminder_parse_city($source) !== '') {
            continue;
        }
        $saved = array_key_exists('ort', $row) && $row['ort'] !== null ? trim((string) $row['ort']) : '';
        if ($saved !== '') {
            continue;
        }
        if (jeab_reminder_ort_cache_get(jeab_reminder_ort_cache_key($row)) !== null) {
            continue;
        }
        $pending = $row;
        break;
    }
    if ($pending === null) {
        return;
    }
    $id = (int) ($pending['id'] ?? 0);
    jeab_defer_after_response(static function () use ($pending, $id): void {
        $store = jeab_store();
        $prev = jeab_reminder_ort_lookup_budget();
        jeab_reminder_ort_lookup_budget(1);
        $city = jeab_reminder_cached_city($pending);
        jeab_reminder_ort_lookup_budget($prev);
        if ($city === '' || $id <= 0) {
            return;
        }
        try {
            $fresh = $store->getReminder($id);
            if (!is_array($fresh) || trim((string) ($fresh['ort'] ?? '')) !== '') {
                return;
            }
            $fields = [
                'ort' => $city,
                'updated_at' => (string) ($fresh['updated_at'] ?? jeab_now()),
            ];
            $last = $GLOBALS['_JEAB_ADDRESS_EXTRACT_LAST'] ?? null;
            if (is_array($last) && trim((string) ($fresh['postal'] ?? '')) === '' && (string) ($last['postal'] ?? '') !== '' && (string) ($last['city'] ?? '') === $city) {
                $fields['postal'] = (string) $last['postal'];
            }
            $store->saveReminder($id, $fields);
        } catch (Throwable) {
        }
    });
}

function jeab_reminder_heading(array $row): string
{
    return jeab_reminder_heading_from_view($row, jeab_reminder_shown_customer($row));
}

function jeab_reminder_heading_from_view(array $row, array $view): string
{
    $street = (string) ($view['street'] ?? '');
    $city = (string) ($view['city'] ?? '');
    $name = (string) ($view['name'] ?? '');
    $parts = [];
    if ($street !== '') {
        $parts[] = $street;
    }
    if ($city !== '' && strcasecmp($city, $street) !== 0 && strcasecmp($city, $name) !== 0) {
        $parts[] = $city;
    }
    if ($name !== '') {
        $parts[] = $name;
    }
    if ($parts !== []) {
        return implode(', ', $parts);
    }
    $title = trim((string) ($row['title'] ?? ''));
    if (function_exists('jeab_name_address_product_title') && jeab_name_address_product_title($title)) {
        return '';
    }

    return $title;
}

function jeab_reminder_title_html(array $row): string
{
    return jeab_reminder_title_html_from_view($row, jeab_reminder_shown_customer($row), jeab_reminder_tap_total($row));
}

/** Samma HTML som jeab_reminder_title_html, utan att läsa kunden en gång till. */
function jeab_reminder_title_html_from_view(array $row, array $view, int $tapTotal): string
{
    $heading = jeab_reminder_heading_from_view($row, $view);
    $html = '<span class="item-title-row"><span class="item-title">' . e($heading) . '</span>';
    if ($tapTotal > 0) {
        $html .= '<span class="item-tap-total">' . $tapTotal . '</span>';
    }

    return $html . '</span>';
}

function jeab_reminder_tap_meta(): array
{
    return [
        'smsat' => ['Smsat', 'reminder-sms.png'],
        'mailat' => ['Mailat', 'reminder-mail.png'],
        'pratat' => ['Samtal', 'reminder-samtal.png'],
        'ringt' => ['Ringda', 'reminder-ringt.png'],
    ];
}

function jeab_reminder_tap_icon_svg(string $key): string
{
    $paths = [
        'smsat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'mailat' => '<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>',
        'pratat' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'ringt' => '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/>',
    ];
    $d = $paths[$key] ?? '';
    if ($d === '') {
        return '';
    }
    $class = $key === 'mailat' ? 'item-tap-svg item-tap-at' : 'item-tap-svg';
    return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

function jeab_reminder_taps_html(array $row, string $listAction): string
{
    $taps = jeab_reminder_tap_counts($row);
    $id = (int) ($row['id'] ?? 0);
    ob_start();
    echo '<div class="item-taps" role="group" aria-label="Kontakter">';
    $req = (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '0');
    if (($GLOBALS['_JEAB_TAP_ICON_REQ'] ?? '') !== $req) {
        $GLOBALS['_JEAB_TAP_ICON_VER'] = [];
        $GLOBALS['_JEAB_TAP_ICON_REQ'] = $req;
    }
    foreach (jeab_reminder_tap_meta() as $key => $meta) {
        $src = jeab_url('assets/img/' . $meta[1]);
        $file = JEAB_ROOT . '/assets/img/' . $meta[1];
        if (!isset($GLOBALS['_JEAB_TAP_ICON_VER'][$file])) {
            $GLOBALS['_JEAB_TAP_ICON_VER'][$file] = is_file($file) ? (int) filemtime($file) : 0;
        }
        $ver = (int) $GLOBALS['_JEAB_TAP_ICON_VER'][$file];
        ?>
        <form class="item-tap-form" method="post" action="<?= e($listAction) ?>" data-js="tap">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="reminder.tap">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="field" value="<?= e($key) ?>">
            <button class="item-tap" type="submit" aria-label="<?= e($meta[0]) ?>">
                <img class="item-tap-ico" src="<?= e($src) ?>?v=<?= $ver ?>" alt="" width="32" height="32">
                <?= jeab_reminder_tap_icon_svg($key) ?>
                <?php if ((int) $taps[$key] > 0): ?>
                    <span class="item-tap-n" data-tap="<?= e($key) ?>"><?= (int) $taps[$key] ?></span>
                <?php else: ?>
                    <span class="item-tap-n" data-tap="<?= e($key) ?>" hidden></span>
                <?php endif; ?>
            </button>
        </form>
        <?php
    }
    echo '</div>';
    return ob_get_clean();
}

function jeab_tap_reminder(JeabStore $store, int $id, string $field): array
{
    $field = trim($field);
    if (!in_array($field, jeab_reminder_tap_keys(), true)) {
        throw new InvalidArgumentException('Okänd knapp.');
    }
    $existing = $store->getReminder($id);
    if (is_array($existing)) {
        jeab_reminder_require_actor($existing);
    }
    // En kolumn: UPDATE … SET fält = fält + 1. Ingen helrad, ingen kontaktifyllnad, ingen webhook.
    return $store->incrementReminderTap($id, $field);
}

/** Ångra på Ringd/Mailat: stämpeln sparas direkt, så ångra tar bort den på servern igen. */
function jeab_untap_reminder(JeabStore $store, int $id, string $field): array
{
    $field = trim($field);
    if (!in_array($field, jeab_reminder_tap_keys(), true)) {
        throw new InvalidArgumentException('Okänd knapp.');
    }
    $existing = $store->getReminder($id);
    if (is_array($existing)) {
        jeab_reminder_require_actor($existing);
    }
    if (!method_exists($store, 'decrementReminderTap')) {
        throw new InvalidArgumentException('Kunde inte ångra.');
    }
    return $store->decrementReminderTap($id, $field);
}

function jeab_reminder_phone(array $row): string
{
    $phone = trim((string) ($row['phone'] ?? ''));
    if ($phone !== '') {
        return $phone;
    }
    $found = jeab_find_phones(jeab_reminder_notes_text($row));
    return $found[0] ?? '';
}

function jeab_reminder_email_address(string $email): string
{
    $email = trim($email);
    if ($email === '' || preg_match('/\s|mail:|https?:|inkorg/iu', $email) === 1) {
        return '';
    }
    if (preg_match('/^[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}$/i', $email) !== 1) {
        return '';
    }
    return $email;
}

function jeab_reminder_email(array $row): string
{
    $email = jeab_reminder_email_address((string) ($row['email'] ?? ''));
    if ($email !== '') {
        return $email;
    }
    foreach (jeab_find_emails(jeab_reminder_notes_text($row)) as $found) {
        $ok = jeab_reminder_email_address($found);
        if ($ok !== '') {
            return $ok;
        }
    }
    return '';
}

function jeab_reminder_contacts_bump(): void
{
    $GLOBALS['_JEAB_REMINDER_CONTACT_GEN'] = (int) ($GLOBALS['_JEAB_REMINDER_CONTACT_GEN'] ?? 0) + 1;
    unset($GLOBALS['_JEAB_REMINDER_CONTACT_INDEX'], $GLOBALS['_JEAB_REMINDER_CONTACT_SIG'], $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID']);
}

/** Kontakten som påminnelsen pekar på, en rad via get($id). Saknas id eller raden är den borta. */
function jeab_reminder_linked_contact(array $row): ?array
{
    $id = trim((string) ($row['contact_id'] ?? ''));
    if ($id === '' || !function_exists('jeab_kontakt_get')) {
        return null;
    }
    $gen = (int) ($GLOBALS['_JEAB_REMINDER_CONTACT_GEN'] ?? 0);
    if (($GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID_GEN'] ?? -1) !== $gen || !is_array($GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID'] ?? null)) {
        $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID'] = [];
        $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID_GEN'] = $gen;
    }
    if (array_key_exists($id, $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID'])) {
        $hit = $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID'][$id];
        return is_array($hit) ? $hit : null;
    }
    $found = jeab_kontakt_get($id);
    $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID'][$id] = is_array($found) ? $found : null;
    return is_array($found) ? $found : null;
}

/** Ett anrop för kontaktuppgifterna på de synliga korten, i stället för en SELECT per rad. */
function jeab_reminder_preload_contacts(array $rows): void
{
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['contact_id'] ?? ''));
        if ($id !== '') {
            $ids[$id] = $id;
        }
    }
    if ($ids === []) {
        return;
    }
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_load_ids')) {
        $loaded = jeab_contacts_sole_load_ids(array_values($ids));
        $gen = (int) ($GLOBALS['_JEAB_REMINDER_CONTACT_GEN'] ?? 0);
        if (($GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID_GEN'] ?? -1) !== $gen || !is_array($GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID'] ?? null)) {
            $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID'] = [];
            $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID_GEN'] = $gen;
        }
        foreach ($ids as $id) {
            $hit = $loaded[$id] ?? null;
            $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID'][$id] = is_array($hit) ? $hit : null;
        }
        if (function_exists('jeab_contacts_sole_prefetch_reminder_names')) {
            jeab_contacts_sole_prefetch_reminder_names($rows);
        }

        return;
    }
    $store = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if (!is_object($store) || !method_exists($store, 'getMany')) {
        return;
    }
    $found = $store->getMany(array_values($ids));
    $gen = (int) ($GLOBALS['_JEAB_REMINDER_CONTACT_GEN'] ?? 0);
    if (($GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID_GEN'] ?? -1) !== $gen || !is_array($GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID'] ?? null)) {
        $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID'] = [];
        $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID_GEN'] = $gen;
    }
    foreach ($ids as $id) {
        $row = $found[$id] ?? null;
        $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID'][$id] = is_array($row) ? $row : null;
    }
    if (function_exists('jeab_contacts_sole_prefetch_reminder_names')) {
        jeab_contacts_sole_prefetch_reminder_names($rows);
    }
}

/**
 * Kunduppgifter som listan, kortet och formuläret visar.
 * Har påminnelsen en kontakt läses namn, gata, postnummer, ort, telefon och e-post där.
 * Den egna kopian används bara när kontakten saknar värdet, eller när contact_id saknas.
 * «Sverige» visas inte.
 *
 * @return array{linked:bool,name:string,email:string,phone:string,street:string,postal:string,city:string,display:string}
 */
function jeab_reminder_shown_customer(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $sig = '';
    if ($id > 0) {
        $sig = $id . '|' . (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '0') . '|' . (string) ($GLOBALS['_JEAB_SHOWN_GEN'] ?? 0) . '|' . md5(
            (string) ($row['updated_at'] ?? '')
            . "\n" . (string) ($row['customer_name'] ?? '')
            . "\n" . (string) ($row['address'] ?? '')
            . "\n" . (string) ($row['email'] ?? '')
            . "\n" . (string) ($row['phone'] ?? '')
            . "\n" . (string) ($row['ort'] ?? '')
            . "\n" . (string) ($row['postal'] ?? '')
            . "\n" . (string) ($row['contact_id'] ?? '')
            . "\n" . (string) ($row['note'] ?? '')
        );
        $shownCache = $GLOBALS['_JEAB_SHOWN_CUSTOMER'] ?? null;
        $hit = is_array($shownCache) ? ($shownCache[$sig] ?? null) : null;
        if (is_array($hit)) {
            return $hit;
        }
    }
    $shown = jeab_reminder_shown_customer_build($row);
    if ($sig !== '') {
        if (!isset($GLOBALS['_JEAB_SHOWN_CUSTOMER']) || !is_array($GLOBALS['_JEAB_SHOWN_CUSTOMER'])) {
            $GLOBALS['_JEAB_SHOWN_CUSTOMER'] = [];
        }
        if (count($GLOBALS['_JEAB_SHOWN_CUSTOMER']) > 300) {
            $GLOBALS['_JEAB_SHOWN_CUSTOMER'] = [];
        }
        $GLOBALS['_JEAB_SHOWN_CUSTOMER'][$sig] = $shown;
    }

    return $shown;
}

function jeab_reminder_shown_customer_build(array $row): array
{
    $rawRow = $row;
    $finish = static function (array $shown) use ($rawRow): array {
        if (function_exists('jeab_name_address_finish_shown')) {
            $done = jeab_name_address_finish_shown($shown, $rawRow);

            return is_array($done) ? $done : $shown;
        }

        return $shown;
    };
    $linkedOverride = null;
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_paint_reminder')) {
        $row = jeab_contacts_sole_paint_reminder($row);
        $linkedOverride = empty($row['contact_link_missing']) && trim((string) ($row['contact_id'] ?? '')) !== '';
        $contact = null;
    } else {
        $contact = jeab_reminder_linked_contact($row);
    }
    $ownName = trim((string) ($row['customer_name'] ?? ''));
    $ownAddress = trim((string) ($row['address'] ?? ''));
    $source = jeab_reminder_address_source($row);
    $parts = jeab_address_parts($source !== '' ? $source : $ownAddress);
    $ownStreet = $parts['street'] !== '' ? $parts['street'] : ($source !== '' ? jeab_reminder_street($source) : '');
    $ownCity = $parts['city'] !== '' ? $parts['city'] : jeab_address_town(jeab_reminder_resolve_city($row, false));
    $ownPostal = $parts['postal'] !== '' ? $parts['postal'] : trim((string) ($row['postal'] ?? ''));
    $ownDisplay = $parts['display'] !== '' ? $parts['display'] : ($ownStreet !== '' ? $ownStreet : $ownAddress);
    if ($ownCity !== '' && $ownDisplay !== '' && stripos($ownDisplay, $ownCity) === false) {
        $ownDisplay .= ', ' . $ownCity;
    }
    if ($contact === null) {
        $email = jeab_reminder_email($row);
        if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_finish_display_name')) {
            $ownName = jeab_contacts_sole_finish_display_name($ownName, [$email, (string) ($row['email'] ?? '')]);
        }
        return $finish([
            'linked' => $linkedOverride ?? false,
            'name' => $ownName,
            'email' => $email,
            'phone' => jeab_reminder_phone($row),
            'street' => $ownStreet,
            'postal' => $ownPostal,
            'city' => $ownCity,
            'display' => $ownDisplay,
        ]);
    }
    $name = function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($contact) : '';
    $email = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($contact) : '';
    $phone = function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($contact) : '';
    $street = trim((string) ($contact['street'] ?? ''));
    $postal = function_exists('jeab_kontakt_postal')
        ? jeab_kontakt_postal((string) ($contact['postal_code'] ?? ''))
        : trim((string) ($contact['postal_code'] ?? ''));
    $city = jeab_address_town((string) ($contact['city'] ?? ''));
    if ($street === '') {
        $street = $ownStreet;
    }
    if ($postal === '') {
        $postal = $ownPostal;
    }
    if ($city === '') {
        $city = $ownCity;
    }
    $composed = jeab_address_parts(trim($street . ($city !== '' ? ', ' . $city : '')));
    if ($composed['street'] !== '') {
        $street = $composed['street'];
    }
    if ($composed['city'] !== '') {
        $city = $composed['city'];
    }
    $display = $composed['display'] !== '' ? $composed['display'] : ($street !== '' ? $street : $ownDisplay);
    if ($city !== '' && $display !== '' && stripos($display, $city) === false) {
        $display .= ', ' . $city;
    }
    return $finish([
        'linked' => true,
        'name' => $name !== '' ? $name : $ownName,
        'email' => $email !== '' ? $email : jeab_reminder_email($row),
        'phone' => $phone !== '' ? $phone : jeab_reminder_phone($row),
        'street' => $street,
        'postal' => $postal,
        'city' => $city,
        'display' => $display,
    ]);
}

/**
 * Kundfält till Ny påminnelse från en inkorgstråd.
 * Kontakten i Kontakter är källan: id på tråden först, annars e-post.
 *
 * @param array<string, mixed> $meta
 * @return array{name:string,address:string,phone:string,email:string}
 */
function jeab_reminder_thread_create_customer(array $meta): array
{
    $empty = ['name' => '', 'address' => '', 'phone' => '', 'email' => ''];
    $contact = jeab_reminder_contact_from_thread_meta($meta);
    if (!is_array($contact)) {
        return $empty;
    }
    if (function_exists('jeab_contacts_sole_view_from_contact')) {
        $view = jeab_contacts_sole_view_from_contact($contact);
        $street = trim((string) ($view['street'] ?? ''));
        $display = trim((string) ($view['display'] ?? ''));

        return [
            'name' => trim((string) ($view['name'] ?? '')),
            'address' => $street !== '' ? $street : $display,
            'phone' => trim((string) ($view['phone'] ?? '')),
            'email' => trim((string) ($view['email'] ?? '')),
        ];
    }
    $street = trim((string) ($contact['street'] ?? ''));

    return [
        'name' => function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($contact) : '',
        'address' => $street,
        'phone' => function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($contact) : '',
        'email' => function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($contact) : '',
    ];
}

/**
 * @param array<string, mixed> $meta
 * @return array<string, mixed>|null
 */
function jeab_reminder_contact_from_thread_meta(array $meta): ?array
{
    $ids = [];
    $id = trim((string) ($meta['contact_id'] ?? ''));
    if ($id !== '') {
        $ids[] = $id;
        if (function_exists('jeab_inkorg_contact_record')) {
            try {
                $inbox = jeab_inkorg_contact_record($id);
            } catch (Throwable) {
                $inbox = null;
            }
            if (is_array($inbox)) {
                $central = trim((string) ($inbox['contact_id'] ?? ''));
                if ($central !== '' && $central !== $id) {
                    $ids[] = $central;
                }
            }
        }
    }
    foreach ($ids as $cid) {
        $found = null;
        if (function_exists('jeab_contacts_sole_linked_contact')) {
            $found = jeab_contacts_sole_linked_contact($cid);
        } elseif (function_exists('jeab_kontakt_get')) {
            $found = jeab_kontakt_get($cid);
        }
        if (is_array($found)) {
            return $found;
        }
    }

    $emails = [];
    $lead = is_array($meta['lead_customer'] ?? null) ? $meta['lead_customer'] : [];
    foreach ([(string) ($meta['customer_email'] ?? ''), (string) ($lead['email'] ?? '')] as $raw) {
        $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($raw) : strtolower(trim($raw));
        if ($email !== '') {
            $emails[$email] = true;
        }
    }
    foreach (array_keys($emails) as $email) {
        $store = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
        if (is_object($store) && method_exists($store, 'findByEmail')) {
            try {
                $hits = $store->findByEmail($email);
            } catch (Throwable) {
                $hits = [];
            }
            foreach (is_array($hits) ? $hits : [] as $hit) {
                if (is_array($hit)) {
                    return $hit;
                }
            }
            continue;
        }
        if (function_exists('jeab_kontakter_load') && function_exists('jeab_kontakt_find')) {
            $all = jeab_kontakter_load();
            $foundId = jeab_kontakt_find($all, '', '', $email, '', false);
            if (is_string($foundId) && $foundId !== '' && isset($all[$foundId]) && is_array($all[$foundId])) {
                return $all[$foundId];
            }
        }
    }

    return null;
}

/**
 * E-post och adress som en automatisk krok ska bedöma.
 * En kopplad påminnelse använder kontakten. Annars den egna kopian.
 *
 * @param array<string, mixed> $row
 * @return array{linked:bool,email:string,address:string,ort:string,postal:string}
 */
function jeab_reminder_automation_customer(array $row): array
{
    $contact = jeab_reminder_linked_contact($row);
    if ($contact === null) {
        return [
            'linked' => false,
            'email' => trim((string) ($row['email'] ?? '')),
            'address' => trim((string) ($row['address'] ?? '')),
            'ort' => trim((string) ($row['ort'] ?? '')),
            'postal' => trim((string) ($row['postal'] ?? '')),
        ];
    }
    $shown = jeab_reminder_shown_customer($row);
    $street = trim((string) ($shown['street'] ?? ''));
    $city = trim((string) ($shown['city'] ?? ''));
    $address = $street;
    if ($city !== '') {
        $address = $address === '' ? $city : $address . ', ' . $city;
    }
    return [
        'linked' => true,
        'email' => trim((string) ($shown['email'] ?? '')),
        'address' => $address,
        'ort' => $city,
        'postal' => trim((string) ($shown['postal'] ?? '')),
    ];
}

/**
 * Utan formulärskrivning fylls bara tomma fält. En annan adress fyller inget.
 *
 * @param array<string, mixed> $contact
 * @param array<string, string> $patch
 * @return array<string, string>
 */
function jeab_reminder_contact_fill_patch(array $contact, array $patch, bool $overwrite): array
{
    if ($overwrite) {
        return $patch;
    }
    $streetIn = trim((string) ($patch['street'] ?? ''));
    $cityIn = trim((string) ($patch['city'] ?? ''));
    $haveStreet = trim((string) ($contact['street'] ?? ''));
    $haveCity = function_exists('jeab_kontakt_city_name')
        ? jeab_kontakt_city_name((string) ($contact['city'] ?? ''))
        : trim((string) ($contact['city'] ?? ''));
    $haveKey = function_exists('jeab_kontakt_effective_street_key')
        ? jeab_kontakt_effective_street_key($haveStreet, (string) ($contact['street_key'] ?? ''))
        : $haveStreet;
    $wantKey = function_exists('jeab_kontakt_street_key') ? jeab_kontakt_street_key($streetIn) : $streetIn;
    $sameStreet = $haveStreet !== '' && $streetIn !== '' && function_exists('jeab_kontakt_street_keys_equal')
        && jeab_kontakt_street_keys_equal($haveKey, $wantKey);
    $cityDiffers = $haveCity !== '' && $cityIn !== '' && function_exists('jeab_kontakt_city_key')
        && jeab_kontakt_city_key($haveCity) !== jeab_kontakt_city_key($cityIn);
    if ($haveStreet !== '' && $streetIn !== '' && (!$sameStreet || $cityDiffers)) {
        unset($patch['street'], $patch['city'], $patch['postal']);
    }
    $empty = [
        'name' => function_exists('jeab_kontakt_row_name') && jeab_kontakt_row_name($contact) === '',
        'email' => function_exists('jeab_kontakt_row_email') && jeab_kontakt_row_email($contact) === '',
        'phone' => function_exists('jeab_kontakt_row_phone') && jeab_kontakt_row_phone($contact) === '',
        'street' => $haveStreet === '',
        'postal' => trim((string) ($contact['postal_code'] ?? '')) === '',
        'city' => $haveCity === '',
    ];
    foreach ($patch as $key => $value) {
        if (!array_key_exists($key, $empty) || !$empty[$key] || trim((string) $value) === '') {
            unset($patch[$key]);
        }
    }
    return $patch;
}

/**
 * Skriver namn, gata, postnummer, ort, telefon och e-post på kontakten.
 * Andra ägare och övriga fält lämnas.
 *
 * @param array<string, string> $patch
 * @return array<string, mixed>|null
 */
function jeab_reminder_save_contact_customer(string $id, array $patch): ?array
{
    $id = trim($id);
    if ($id === '' || $patch === [] || !function_exists('jeab_kontakter_mutate')) {
        return null;
    }
    $saved = jeab_kontakter_mutate(function (array &$contacts) use ($id, $patch): ?array {
        if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
            return null;
        }
        $row = $contacts[$id];
        $owners = [];
        foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
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
            $owners[] = ['name' => '', 'personnummer' => '', 'contact' => true, 'email' => '', 'phone' => ''];
        }
        $owners[$index]['contact'] = true;
        if (array_key_exists('name', $patch)) {
            $owners[$index]['name'] = trim($patch['name']);
        }
        if (array_key_exists('email', $patch)) {
            $owners[$index]['email'] = function_exists('jeab_kontakt_email')
                ? jeab_kontakt_email($patch['email'])
                : trim($patch['email']);
        }
        if (array_key_exists('phone', $patch)) {
            $rawPhone = (string) $patch['phone'];
            if (function_exists('jeab_contacts_gap_phone')) {
                $owners[$index]['phone'] = jeab_contacts_gap_phone($rawPhone);
            } elseif (function_exists('jeab_kontakt_phone')) {
                $owners[$index]['phone'] = jeab_kontakt_phone($rawPhone);
            } else {
                $owners[$index]['phone'] = trim($rawPhone);
            }
        }
        $row['owners'] = $owners;
        if (array_key_exists('street', $patch)) {
            $street = trim($patch['street']);
            $row['street'] = $street;
            $row['street_key'] = function_exists('jeab_kontakt_street_key') ? jeab_kontakt_street_key($street) : '';
        }
        if (array_key_exists('postal', $patch)) {
            $row['postal_code'] = function_exists('jeab_kontakt_postal')
                ? jeab_kontakt_postal($patch['postal'])
                : trim($patch['postal']);
        }
        if (array_key_exists('city', $patch)) {
            $row['city'] = function_exists('jeab_kontakt_city_name')
                ? jeab_kontakt_city_name($patch['city'])
                : trim($patch['city']);
        }
        $row['id'] = $id;
        $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $contacts[$id] = $row;
        return $row;
    });
    jeab_reminder_contacts_bump();
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($id);
    }
    return is_array($saved) ? $saved : null;
}

/**
 * Kopplar en ny påminnelse till en kontakt och fyller tom ort och postnummer.
 * Den egna kundkopian skrivs inte om.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $hint
 * @return array<string, mixed>
 */
function jeab_reminder_same_party(array $existing, array $details, string $postal, string $address): bool
{
    $wantEmail = mb_strtolower(trim((string) ($details['email'] ?? '')));
    $haveEmail = mb_strtolower(trim((string) ($existing['email'] ?? '')));
    if ($wantEmail !== '' && $haveEmail !== '' && $wantEmail === $haveEmail) {
        return true;
    }
    $wantPhone = jeab_reminder_phone_key((string) ($details['phone'] ?? ''));
    $havePhone = jeab_reminder_phone_key((string) ($existing['phone'] ?? ''));
    if ($wantPhone !== '' && $havePhone !== '' && $wantPhone === $havePhone) {
        return true;
    }
    $wantPostal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal($postal) : '';
    if ($wantPostal === '') {
        $wantPostal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal((string) ($details['address'] ?? $address)) : '';
    }
    $havePostal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal((string) ($existing['postal'] ?? '')) : '';
    if ($wantPostal === '' || $havePostal === '' || $wantPostal !== $havePostal) {
        return false;
    }
    if (!function_exists('jeab_kontakt_parse_address') || !function_exists('jeab_norm_street_key')) {
        return false;
    }
    $wantParsed = jeab_kontakt_parse_address(trim((string) ($details['address'] ?? $address)));
    $haveParsed = jeab_kontakt_parse_address(trim((string) ($existing['address'] ?? '')));
    $wantKey = jeab_norm_street_key((string) ($wantParsed['street'] ?? ''), $wantPostal, '');
    $haveKey = jeab_norm_street_key((string) ($haveParsed['street'] ?? ''), $havePostal, '');

    return $wantKey !== '' && $wantKey === $haveKey && preg_match('/\|\d{5}$/', $wantKey) === 1;
}

function jeab_reminder_bind_contact(JeabStore $store, array $row, array $hint = []): array
{
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_kontakt_find_or_create')) {
        return $row;
    }
    $requirePostal = !empty($hint['require_postal']) || !empty($GLOBALS['_JEAB_LEAD_REQUIRE_POSTAL']);
    $prevRequire = $GLOBALS['_JEAB_LEAD_REQUIRE_POSTAL'] ?? null;
    if ($requirePostal) {
        $GLOBALS['_JEAB_LEAD_REQUIRE_POSTAL'] = true;
    }
    try {
        $preset = trim((string) ($row['contact_id'] ?? ''));
        if ($preset === '') {
            $preset = trim((string) ($hint['contact_id'] ?? ''));
        }
        $contact = null;
        if ($preset !== '') {
            if (trim((string) ($row['contact_id'] ?? '')) === '' && function_exists('jeab_kontakt_stamp_reminder')) {
                jeab_kontakt_stamp_reminder($store, $id, $preset);
            }
            $contact = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($preset) : null;
        } else {
            $lookup = !array_key_exists('lookup', $hint)
                || !($hint['lookup'] === false || $hint['lookup'] === 0 || (string) $hint['lookup'] === '0');
            $contact = jeab_kontakt_find_or_create([
                'name' => trim((string) ($hint['name'] ?? $row['customer_name'] ?? '')),
                'email' => trim((string) ($hint['email'] ?? $row['email'] ?? '')),
                'phone' => trim((string) ($hint['phone'] ?? $row['phone'] ?? '')),
                'address' => trim((string) ($hint['address'] ?? $row['address'] ?? '')),
                'city' => trim((string) ($hint['city'] ?? $row['ort'] ?? '')),
                'postal_code' => trim((string) ($hint['postal_code'] ?? $row['postal'] ?? '')),
                'company' => (string) ($hint['company'] ?? $row['company'] ?? ''),
                'thread_id' => (string) ($hint['thread_id'] ?? $row['thread_id'] ?? ''),
                'require_postal' => $requirePostal,
                'email_link' => true,
            ], $lookup, false, true);
            if (is_array($contact) && (string) ($contact['id'] ?? '') !== '' && function_exists('jeab_kontakt_stamp_reminder')) {
                jeab_kontakt_stamp_reminder($store, $id, (string) $contact['id']);
            }
        }
        jeab_reminder_contacts_bump();
        $fresh = $store->getReminder($id);
        if (!is_array($fresh)) {
            return $row;
        }
        $linkedId = trim((string) ($fresh['contact_id'] ?? ''));
        if ($linkedId === '' && is_array($contact)) {
            $linkedId = trim((string) ($contact['id'] ?? ''));
        }
        if ($linkedId !== '' && function_exists('jeab_kontakt_note_reminder_address')) {
            $noted = jeab_kontakt_note_reminder_address($linkedId, $id, [
                'name' => trim((string) ($hint['name'] ?? $fresh['customer_name'] ?? '')),
                'email' => trim((string) ($hint['email'] ?? $fresh['email'] ?? '')),
                'phone' => trim((string) ($hint['phone'] ?? $fresh['phone'] ?? '')),
                'address' => trim((string) ($hint['address'] ?? $fresh['address'] ?? '')),
                'city' => trim((string) ($hint['city'] ?? $fresh['ort'] ?? '')),
                'postal_code' => trim((string) ($hint['postal_code'] ?? $fresh['postal'] ?? '')),
            ]);
            if (is_array($noted)) {
                $contact = $noted;
            }
        }
        $extra = [];
        $city = is_array($contact) ? jeab_address_town((string) ($contact['city'] ?? '')) : '';
        $postal = is_array($contact) && function_exists('jeab_kontakt_postal')
            ? jeab_kontakt_postal((string) ($contact['postal_code'] ?? ''))
            : '';
        if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole()) {
            if (trim((string) ($fresh['ort'] ?? '')) === '' && $city !== '') {
                $extra['ort'] = $city;
            }
            if (trim((string) ($fresh['postal'] ?? '')) === '' && $postal !== '') {
                $extra['postal'] = $postal;
            }
            if ($extra !== []) {
                $fresh = $store->saveReminder($id, $extra);
            }
        }
        if (is_array($fresh) && function_exists('jeab_contacts_sole_link_record') && jeab_contacts_sole() && trim((string) ($fresh['contact_id'] ?? '')) === '') {
            $fresh = jeab_contacts_sole_link_record($store, 'reminder', $fresh);
        }
        return is_array($fresh) ? $fresh : $row;
    } catch (Throwable) {
        return $row;
    } finally {
        if ($prevRequire === null) {
            unset($GLOBALS['_JEAB_LEAD_REQUIRE_POSTAL']);
        } else {
            $GLOBALS['_JEAB_LEAD_REQUIRE_POSTAL'] = $prevRequire;
        }
    }
}

function jeab_find_emails(string $text): array
{
    if ($text === '' || !preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $matches)) {
        return [];
    }
    $out = [];
    foreach ($matches[0] as $email) {
        $key = function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
        if (!isset($out[$key])) {
            $out[$key] = $email;
        }
    }
    return array_values($out);
}

function jeab_mailto_href(string $email): string
{
    $email = jeab_reminder_email_address($email);
    return $email === '' ? '' : 'mailto:' . $email;
}

/**
 * Tel / E-post / Adress i anteckningen. Tomma etiketter och «mail:»-länken räknas inte.
 * Bara ett riktigt nummer, en e-postadress eller en adressrad.
 *
 * @return array{phone:string,email:string,address:string}
 */
function jeab_reminder_note_labeled_values(string $note): array
{
    $out = ['phone' => '', 'email' => '', 'address' => ''];
    if ($note === '' || preg_match_all('/(Tel|E-post|Adress)\s*:\s*(.*?)(?=\s*(?:Tel|E-post|Adress)\s*:|\s*mail:\s*https?:\/\/|$)/imu', $note, $matches, PREG_SET_ORDER) < 1) {
        return $out;
    }
    foreach ($matches as $row) {
        $label = function_exists('mb_strtolower') ? mb_strtolower((string) $row[1], 'UTF-8') : strtolower((string) $row[1]);
        $val = trim((string) $row[2]);
        $val = preg_replace('/,\s*Sverige\s*$/iu', '', $val) ?? $val;
        $val = trim($val, " \t\n\r,");
        if ($val === '' || preg_match('/^mail:\s*https?:\/\//iu', $val) === 1) {
            continue;
        }
        if ($label === 'tel' && $out['phone'] === '') {
            $phones = jeab_find_phones($val);
            if ($phones !== []) {
                $out['phone'] = $phones[0];
            }
        } elseif ($label === 'e-post' && $out['email'] === '') {
            $email = jeab_reminder_email_address($val);
            if ($email !== '') {
                $out['email'] = $email;
            }
        } elseif ($label === 'adress' && $out['address'] === '') {
            $out['address'] = $val;
        }
    }
    return $out;
}

/** Etikett utan värde, dubblett som redan ligger i ett fält, eller bara inkorgslänken. */
function jeab_reminder_label_line_is_droppable(string $line): bool
{
    if (preg_match_all('/(Tel|E-post|Adress)\s*:\s*(.*?)(?=\s*(?:Tel|E-post|Adress)\s*:|\s*mail:\s*https?:\/\/|$)/imu', $line, $matches, PREG_SET_ORDER) < 1) {
        return preg_match('/mail:\s*https?:\/\/\S*inkorg/iu', $line) === 1;
    }
    foreach ($matches as $row) {
        $label = function_exists('mb_strtolower') ? mb_strtolower((string) $row[1], 'UTF-8') : strtolower((string) $row[1]);
        $val = trim((string) $row[2]);
        $val = preg_replace('/,\s*Sverige\s*$/iu', '', $val) ?? $val;
        $val = trim($val, " \t\n\r,");
        if ($val === '' || preg_match('/^mail:\s*https?:\/\//iu', $val) === 1) {
            continue;
        }
        if ($label === 'tel' && jeab_find_phones($val) !== []) {
            continue;
        }
        if ($label === 'e-post' && jeab_reminder_email_address($val) !== '') {
            continue;
        }
        if ($label === 'adress') {
            continue;
        }
        return false;
    }
    return true;
}

/** En egen rad «mail:» plus inkorgsadress. Den länkar ett ärende och får stå kvar i anteckningen. */
function jeab_reminder_note_is_mail_link(string $line): bool
{
    return preg_match('/^\s*mail:\s*https?:\/\/(?:www\.)?jeab\.eu\/\S*?p=inkorg(?:&|&amp;)id=[A-Za-z0-9_%.\-]+\s*$/iu', trim($line)) === 1;
}

/** Tar bort flyttade eller tomma etiketter. En egen mail-rad står kvar. Övrig text står kvar. */
function jeab_reminder_note_without_moved_labels(string $note): string
{
    $lines = preg_split("/\r\n|\n|\r/", $note) ?: [];
    $keep = [];
    foreach ($lines as $line) {
        if (jeab_reminder_note_is_mail_link($line)) {
            $keep[] = trim($line);
            continue;
        }
        if (preg_match('/(?:Tel|E-post|Adress)\s*:|mail:\s*https?:\/\/\S*inkorg/iu', $line) === 1 && jeab_reminder_label_line_is_droppable($line)) {
            $rest = preg_replace('/(?:Tel|E-post|Adress)\s*:\s*(?:(?!(?:Tel|E-post|Adress)\s*:|mail:\s*https?:).)*/iu', ' ', $line) ?? $line;
            $rest = preg_replace('/\s*mail:\s*https?:\/\/(?:www\.)?jeab\.eu\/\S*?p=inkorg(?:&|&amp;)id=[A-Za-z0-9_%.\-]+/iu', ' ', $rest) ?? $rest;
            $rest = preg_replace('/,\s*Sverige\b/iu', '', $rest) ?? $rest;
            $rest = trim(preg_replace('/\s{2,}/', ' ', $rest) ?? $rest, " \t,");
            if ($rest === '') {
                continue;
            }
            $keep[] = $rest;
            continue;
        }
        $line = preg_replace('/\s*mail:\s*https?:\/\/(?:www\.)?jeab\.eu\/\S*?p=inkorg(?:&|&amp;)id=[A-Za-z0-9_%.\-]+/iu', ' ', $line) ?? $line;
        $keep[] = rtrim($line);
    }
    $text = trim(implode("\n", $keep));
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}

/** Anteckning som visas. E-post och inkorgslänken har egna rader, så de tas ur texten. */
function jeab_reminder_view_note(string $note): string
{
    if (function_exists('jeab_text_drop_duplicate_via_lines')) {
        $note = jeab_text_drop_duplicate_via_lines($note);
    }
    if ($note === '') {
        return '';
    }
    // Samma resultat som radloopen när ingen etikett eller inkorgsrad finns.
    if (stripos($note, 'Tel') === false && stripos($note, 'E-post') === false && stripos($note, 'Adress') === false && stripos($note, 'mail:') === false) {
        if (!str_contains($note, "\r") && !str_contains($note, "\n\n\n")) {
            return trim($note);
        }
        $text = str_replace(["\r\n", "\r"], ["\n", "\n"], $note);
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text)) ?? trim($text);

        return trim($text);
    }
    $lines = preg_split("/\r\n|\n|\r/", $note) ?: [];
    $keep = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*(?:Tel|E-post|Adress)\s*:/iu', $line) === 1) {
            continue;
        }
        if (preg_match('/^\s*mail:\s*https?:\/\/jeab\.eu\/index\.php\?p=inkorg&id=/iu', $line) === 1) {
            continue;
        }
        $keep[] = $line;
    }
    $text = trim(implode("\n", $keep));
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}

/**
 * http/https i anteckningen blir länkar. Övrig text är escapad.
 * javascript: och taggar blir text.
 */
function jeab_reminder_note_html(string $note): string
{
    $escaped = htmlspecialchars(jeab_reminder_view_note($note), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($escaped === '' || stripos($escaped, 'http') === false) {
        return $escaped;
    }
    $linked = preg_replace_callback(
        '~https?://[^\s<]+~i',
        static function (array $m): string {
            $url = htmlspecialchars_decode($m[0], ENT_QUOTES);
            $url = rtrim($url, '.,);');
            if (preg_match('#^https?://#i', $url) !== 1) {
                return $m[0];
            }
            $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return '<a href="' . $safe . '">' . $safe . '</a>';
        },
        $escaped
    );
    return is_string($linked) ? $linked : $escaped;
}

/** Direktlänk till kontakten när påminnelsen har contact_id. */
function jeab_reminder_contact_link_html(array $row): string
{
    $id = trim((string) ($row['contact_id'] ?? ''));
    if ($id === '') {
        return '';
    }
    $href = jeab_url('index.php?' . http_build_query(['p' => 'kontakter', 'id' => $id]));
    return '<a class="item-kontakt" href="' . e($href) . '">Kontakt</a>';
}

function jeab_reminder_inkorg_link_html(array $row): string
{
    $tid = trim((string) ($row['thread_id'] ?? ''));
    if ($tid === '') {
        $tid = jeab_reminder_thread_from_text(jeab_reminder_notes_text($row));
    }
    if ($tid === '' || preg_match('/^[A-Za-z0-9_%.\-]+$/', $tid) !== 1) {
        return '';
    }
    $href = htmlspecialchars(jeab_url('index.php?p=inkorg&id=' . rawurlencode($tid)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<a class="item-inkorg" href="' . $href . '">Öppna ärendet</a>';
}

/**
 * Anteckningen som sparas. Tomma etiketter och värden som flyttats till fält tas bort.
 * Text som inte är telefon, e-post eller adress får stå kvar.
 */
function jeab_reminder_restore_hidden_lines(string $previous, ?string $next): ?string
{
    $text = trim((string) $next);
    $lines = preg_split("/\r\n|\n|\r/", $previous) ?: [];
    $keep = [];
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            continue;
        }
        if (preg_match('/(?:Tel|E-post|Adress)\s*:|mail:\s*https?:\/\/\S*inkorg/iu', $trim) !== 1) {
            continue;
        }
        if (jeab_reminder_label_line_is_droppable($trim)) {
            continue;
        }
        if ($text !== '' && str_contains($text, $trim)) {
            continue;
        }
        $keep[] = $trim;
    }
    if ($keep !== []) {
        $text = trim($text . "\n" . implode("\n", $keep));
    }
    $text = jeab_reminder_note_without_moved_labels($text);
    return $text === '' ? null : $text;
}

function jeab_find_phones(string $text): array
{
    if ($text === '') {
        return [];
    }
    $pattern = '/(?<!\d)(?:\+|00\s*)?46[\s\-.]*\d(?:[\s\-.]*\d){7,11}|(?<!\d)0[1-9](?:[\s\-.]*\d){6,11}(?!\d)/u';
    if (!preg_match_all($pattern, $text, $matches)) {
        return [];
    }
    $found = [];
    $seen = [];
    foreach ($matches[0] as $raw) {
        $raw = trim($raw);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            continue;
        }
        if (isset($seen[$digits])) {
            continue;
        }
        $seen[$digits] = true;
        $found[] = $raw;
    }
    return $found;
}

function jeab_phone_href_number(string $phone): string
{
    $norm = jeab_norm_phone($phone);
    if ($norm !== '') {
        return $norm;
    }
    $digits = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';
    if (str_starts_with($digits, '00')) {
        $digits = '+' . substr($digits, 2);
    }
    if (str_starts_with($digits, '+')) {
        return '+' . (preg_replace('/\D/', '', $digits) ?? '');
    }
    $only = preg_replace('/\D/', '', $digits) ?? '';
    if (str_starts_with($only, '46') && strlen($only) >= 10) {
        return '+' . $only;
    }
    if (str_starts_with($only, '0') && strlen($only) >= 8) {
        return '+46' . substr($only, 1);
    }
    return $only;
}

function jeab_reminder_phone_error(): string
{
    return 'Ange ett svenskt mobilnummer (07… eller +467…).';
}

function jeab_normalize_reminder_phone(mixed $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $span = function_exists('jeab_phone_swedish_span') ? jeab_phone_swedish_span($raw) : '';
    $probe = $span !== '' ? $span : (function_exists('jeab_phone_ascii') ? jeab_phone_ascii($raw) : $raw);
    $norm = jeab_norm_phone($probe);
    if (preg_match('/^\+467\d{8}$/', $norm) !== 1) {
        throw new InvalidArgumentException(jeab_reminder_phone_error());
    }
    return $norm;
}

function jeab_reminder_view(): string
{
    $view = strtolower(trim((string) ($_GET['v'] ?? '')));
    if ($view === 'flagged') {
        return 'waiting';
    }
    $allowed = ['today', 'scheduled', 'all', 'waiting', 'done'];
    return in_array($view, $allowed, true) ? $view : 'today';
}

function jeab_reminder_is_waiting(array $row, ?string $now = null): bool
{
    if (!empty($row['completed_at'])) {
        return false;
    }
    $due = $row['due_at'] ?? null;
    if (!$due) {
        return false;
    }
    return (string) $due > ($now ?? jeab_now());
}

function jeab_reminder_in_today(array $row, ?string $today = null, ?string $now = null): bool
{
    if (!empty($row['completed_at'])) {
        return false;
    }
    $due = $row['due_at'] ?? null;
    if (!$due) {
        return false;
    }
    $today = $today ?? (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
    $dueStr = (string) $due;
    if (substr($dueStr, 0, 10) > $today) {
        return false;
    }
    return $dueStr <= ($now ?? jeab_now());
}

/** Öppen påminnelse vars datum (dag) har passerat: hamnar i gruppen «Försenat». */
function jeab_reminder_is_late(array $row, ?string $today = null): bool
{
    if (!empty($row['completed_at'])) {
        return false;
    }
    $due = trim((string) ($row['due_at'] ?? ''));
    if ($due === '') {
        return false;
    }
    if ($today === null) {
        $cachedToday = $GLOBALS['_JEAB_TODAY_YMD'] ?? null;
        if (!is_string($cachedToday) || $cachedToday === '') {
            $cachedToday = (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
            $GLOBALS['_JEAB_TODAY_YMD'] = $cachedToday;
        }
        $today = $cachedToday;
    }
    return substr($due, 0, 10) < $today;
}

/** Bara raderna som taggfiltret släpper igenom (för flikarnas siffror). */
function jeab_filter_reminders_by_tag(array $rows, string|array|null $tag): array
{
    $mode = jeab_reminder_tag_mode($tag);
    if ($mode['mode'] === 'all') {
        return $rows;
    }
    $out = [];
    foreach ($rows as $row) {
        $tags = jeab_parse_tags($row['tags'] ?? []);
        if ($mode['mode'] === 'none') {
            if ($tags === []) {
                $out[] = $row;
            }
            continue;
        }
        foreach ($tags as $name) {
            if (strcasecmp($name, (string) $mode['tag']) === 0) {
                $out[] = $row;
                break;
            }
        }
    }
    return $out;
}

function jeab_reminder_tag_is_all(string $raw): bool
{
    return strcasecmp(trim($raw), 'all') === 0;
}

function jeab_reminder_tag_is_none(string $raw): bool
{
    return strcasecmp(trim($raw), 'none') === 0;
}

function jeab_reminder_tag_filter(): array
{
    if (!array_key_exists('tag', $_GET)) {
        return ['mode' => 'none', 'tag' => null];
    }
    $raw = trim((string) $_GET['tag']);
    if (jeab_reminder_tag_is_all($raw)) {
        return ['mode' => 'all', 'tag' => null];
    }
    if ($raw === '' || jeab_reminder_tag_is_none($raw)) {
        return ['mode' => 'none', 'tag' => null];
    }
    $tag = jeab_normalize_tag($raw);
    if ($tag !== null && jeab_reminder_tag_hidden($tag)) {
        return ['mode' => 'all', 'tag' => null];
    }
    if ($tag !== null) {
        return ['mode' => 'tag', 'tag' => $tag];
    }
    return ['mode' => 'all', 'tag' => null];
}

function jeab_reminder_tag_query(?array $filter = null): string
{
    $filter ??= jeab_reminder_tag_filter();
    return match ($filter['mode'] ?? 'none') {
        'all' => 'all',
        'tag' => (string) ($filter['tag'] ?? 'none'),
        default => 'none',
    };
}

function jeab_reminder_tag_mode(string|array|null $tag): array
{
    if (is_array($tag)) {
        $mode = (string) ($tag['mode'] ?? 'all');
        $want = isset($tag['tag']) && is_string($tag['tag']) ? jeab_normalize_tag($tag['tag']) : null;
        if ($mode === 'tag' && $want === null) {
            return ['mode' => 'all', 'tag' => null];
        }
        if ($mode === 'none') {
            return ['mode' => 'none', 'tag' => null];
        }
        if ($mode === 'all') {
            return ['mode' => 'all', 'tag' => null];
        }
        return ['mode' => 'tag', 'tag' => $want];
    }
    if (!is_string($tag) || trim($tag) === '') {
        return ['mode' => 'all', 'tag' => null];
    }
    if (jeab_reminder_tag_is_none($tag)) {
        return ['mode' => 'none', 'tag' => null];
    }
    if (jeab_reminder_tag_is_all($tag)) {
        return ['mode' => 'all', 'tag' => null];
    }
    $want = jeab_normalize_tag($tag);
    return $want !== null
        ? ['mode' => 'tag', 'tag' => $want]
        : ['mode' => 'all', 'tag' => null];
}

function jeab_reminder_list_scope(string $view, string|array|null $tag, string $query): array
{
    if (trim($query) !== '') {
        return [
            'view' => 'all',
            'tag' => ['mode' => 'all', 'tag' => null],
        ];
    }
    return [
        'view' => $view,
        'tag' => $tag,
    ];
}

function jeab_filter_reminders(array $rows, string $view, string|array|null $tag, string $query): array
{
    $today = (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
    $tagMode = jeab_reminder_tag_mode($tag);
    $query = function_exists('mb_strtolower') ? mb_strtolower(trim($query), 'UTF-8') : strtolower(trim($query));
    // åäö ska matcha som de står. jeab_norm_fold får inte vika sökningen.
    // Sök i Alla träffar även klarmarkerade (de hamnar efter de öppna).
    // Alla utan sök gör samma sak, så Klara aldrig kan vara fler än Alla.
    $searchAll = $query !== '' && !in_array($view, ['today', 'scheduled', 'waiting', 'flagged', 'done'], true);
    $tailDone = $view === 'all' || $searchAll;
    $out = [];
    $doneHits = [];
    foreach ($rows as $row) {
        $done = !empty($row['completed_at']);
        $due = $row['due_at'] ?? null;
        $ok = match ($view) {
            'today' => jeab_reminder_in_today($row, $today),
            'scheduled' => !$done && $due,
            'waiting', 'flagged' => jeab_reminder_is_waiting($row),
            'done' => $done,
            default => true,
        };
        if (!$ok) {
            continue;
        }
        $tags = jeab_parse_tags($row['tags'] ?? []);
        if ($tagMode['mode'] === 'none') {
            if ($tags !== []) {
                continue;
            }
        } elseif ($tagMode['mode'] === 'tag' && $tagMode['tag'] !== null) {
            $hit = false;
            foreach ($tags as $name) {
                if (strcasecmp($name, $tagMode['tag']) === 0) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
        }
        if ($query !== '') {
            if (!isset($soleWarmed) && function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_reminder_preload_contacts')) {
                $soleWarmed = true;
                jeab_reminder_preload_contacts($rows);
            }
            $shown = jeab_reminder_shown_customer($row);
            $sole = function_exists('jeab_contacts_sole') && jeab_contacts_sole();
            $hay = implode(' ', $sole ? [
                $row['title'] ?? '',
                $shown['name'],
                $shown['display'],
                $shown['street'],
                $shown['city'],
                $shown['postal'],
                $shown['email'],
                $shown['phone'],
                jeab_reminder_notes_text($row),
                implode(' ', $tags),
            ] : [
                $row['title'] ?? '',
                $row['customer_name'] ?? '',
                $shown['name'],
                $row['address'] ?? '',
                $shown['display'],
                $shown['street'],
                $shown['city'],
                $shown['postal'],
                $row['email'] ?? '',
                $shown['email'],
                $row['phone'] ?? '',
                $shown['phone'],
                jeab_reminder_notes_text($row),
                implode(' ', $tags),
            ]);
            $hay = function_exists('mb_strtolower') ? mb_strtolower($hay, 'UTF-8') : strtolower($hay);
            if (!str_contains($hay, $query)) {
                continue;
            }
        }
        if ($tailDone && $done) {
            $doneHits[] = $row;
            continue;
        }
        $out[] = $row;
    }
    if ($view === 'done') {
        $out = jeab_sort_completed_reminders($out);
    }
    if ($view === 'today') {
        // «Försenat» överst, sedan dagens. Ordningen inom grupperna behålls.
        $late = [];
        $rest = [];
        foreach ($out as $row) {
            if (jeab_reminder_is_late($row, $today)) {
                $late[] = $row;
            } else {
                $rest[] = $row;
            }
        }
        $out = array_merge($late, $rest);
    }
    if ($doneHits !== []) {
        $out = array_merge($out, jeab_sort_completed_reminders($doneHits));
    }
    return $out;
}

/** Listvyer utan sök visas sidvis (50). Sök visar träffarna. */
function jeab_reminder_view_paged(string $view, string $search): bool
{
    unset($view);
    return trim($search) === '';
}

/**
 * Hur många rader den här träffen får rita.
 * Vanlig sida och n= stannar på en sida. Sync får läsa fönstret klienten redan har,
 * men jeab_reminder_sync_payload ritar bara kort som saknar samma stämpel.
 */
function jeab_reminder_draw_limit(int $requested, string $intent = ''): int
{
    if ($intent === 'more') {
        return JEAB_REMINDER_DONE_PAGE;
    }
    if ($intent === 'sync' || $intent === 'pulse') {
        $n = $requested > 0 ? $requested : JEAB_REMINDER_DONE_PAGE;

        return min(JEAB_REMINDER_SYNC_WINDOW, max(1, $n));
    }

    return JEAB_REMINDER_DONE_PAGE;
}

/**
 * En sida av listan. $limit över sidstorleken klipps. Ett öppet kort utanför
 * sidan läggs sist, så n= inte ritar hela listan fram till kortet.
 *
 * @param list<array<string, mixed>> $rows
 * @return array{rows:list<array<string, mixed>>,total:int,offset:int,next:?int}
 */
function jeab_reminder_page(array $rows, string $view, string $search, int $offset = 0, int $limit = 0, int $openId = 0): array
{
    $rows = array_values($rows);
    $total = count($rows);
    if (!jeab_reminder_view_paged($view, $search)) {
        return ['rows' => $rows, 'total' => $total, 'offset' => 0, 'next' => null];
    }
    $offset = max(0, min($offset, $total));
    $limit = $limit <= 0 ? JEAB_REMINDER_DONE_PAGE : min($limit, JEAB_REMINDER_DONE_PAGE);
    $slice = array_slice($rows, $offset, $limit);
    if ($openId > 0 && $offset === 0) {
        $seen = false;
        foreach ($slice as $row) {
            if ((int) ($row['id'] ?? 0) === $openId) {
                $seen = true;
                break;
            }
        }
        if (!$seen) {
            foreach ($rows as $row) {
                if ((int) ($row['id'] ?? 0) === $openId) {
                    $row['_jeab_open_extra'] = true;
                    $slice[] = $row;
                    break;
                }
            }
        }
    }
    $end = $offset + count($slice);
    if ($openId > 0 && $slice !== [] && !empty($slice[count($slice) - 1]['_jeab_open_extra'])) {
        $end = $offset + $limit;
    }

    return [
        'rows' => $slice,
        'total' => $total,
        'offset' => $offset,
        'next' => $end < $total ? $end : null,
    ];
}

function jeab_sort_completed_reminders(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($b['completed_at'] ?? ''), (string) ($a['completed_at'] ?? ''));
        return $cmp !== 0 ? $cmp : ((int) $b['id'] <=> (int) $a['id']);
    });
    return $rows;
}

function jeab_completed_reminders(array $rows): array
{
    $done = [];
    foreach ($rows as $row) {
        if (!empty($row['completed_at'])) {
            $done[] = $row;
        }
    }
    return $done;
}

function jeab_prune_stamp_path(): string
{
    return jeab_data_dir() . '/reminders-prune.json';
}

function jeab_prune_due(int $interval = JEAB_REMINDER_PRUNE_INTERVAL): bool
{
    if (!function_exists('jeab_biz_doc_load')) {
        require_once __DIR__ . '/biz_doc_store.php';
    }
    if (function_exists('jeab_biz_doc_use_sql') && jeab_biz_doc_use_sql('reminders_prune')) {
        $doc = jeab_biz_doc_load('reminders_prune');
        $at = (int) ($doc['at'] ?? 0);
        return $at <= 0 || (time() - $at) >= $interval;
    }
    $path = jeab_prune_stamp_path();
    if (!is_file($path)) {
        return true;
    }
    $raw = @file_get_contents($path);
    $at = (int) (is_string($raw) ? (json_decode($raw, true)['at'] ?? 0) : 0);
    return (time() - $at) >= $interval;
}

function jeab_prune_stamp(): void
{
    if (!jeab_private_data_writable()) {
        return;
    }
    $payload = ['at' => time()];
    if (!function_exists('jeab_biz_doc_save')) {
        require_once __DIR__ . '/biz_doc_store.php';
    }
    if (function_exists('jeab_biz_doc_use_sql') && jeab_biz_doc_use_sql('reminders_prune')) {
        jeab_biz_doc_save('reminders_prune', $payload);
        return;
    }
    @file_put_contents(jeab_prune_stamp_path(), json_encode($payload), LOCK_EX);
}

/**
 * Hård radering av klara påminnelser är avstängd.
 * Sidvisning, slutförande och andra automatiska svep får inte anropa deleteReminders.
 */
function jeab_prune_completed_reminders(JeabStore $store, bool $force = false): int
{
    unset($store, $force);

    return 0;
}

function jeab_prune_completed_reminders_now(JeabStore $store): int
{
    unset($store);

    return 0;
}

/**
 * @return list<string>
 */
function jeab_reminders_backup_dirs(): array
{
    $dirs = [];
    foreach ([jeab_data_dir(), function_exists('jeab_public_data_dir') ? jeab_public_data_dir() : ''] as $dir) {
        $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
        if ($dir !== '' && is_dir($dir)) {
            $dirs[$dir] = true;
        }
    }
    if (function_exists('jeab_json_path')) {
        $parent = rtrim(str_replace('\\', '/', dirname(jeab_json_path())), '/');
        if ($parent !== '' && $parent !== '.' && is_dir($parent)) {
            $dirs[$parent] = true;
        }
    }

    return array_keys($dirs);
}

function jeab_reminders_backup_inside(string $file, array $dirs): bool
{
    $real = realpath($file);
    if ($real === false) {
        return false;
    }
    $real = str_replace('\\', '/', $real);
    foreach ($dirs as $dir) {
        $root = realpath($dir);
        if ($root === false) {
            continue;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($real === $root || str_starts_with($real, $root . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * Nyaste JSON-kopian eller exporten som innehåller påminnelse-id.
 * store.json är exporten. kontakter.bak-* är den daterade kopian med id.
 *
 * @return array{file:string,mtime:int,ids:array<int,true>,booking_ids:array<int,true>}|null
 */
function jeab_reminders_newest_backup(): ?array
{
    $dirs = jeab_reminders_backup_dirs();
    $patterns = [
        'store.json',
        'store.bak-*.json',
        'reminders.json',
        'reminders.bak-*.json',
        'reminders-export*.json',
        'kontakter.bak-*.json',
    ];
    $bestPath = '';
    $bestMtime = -1;
    $bestName = '';
    foreach ($dirs as $dir) {
        foreach ($patterns as $pattern) {
            $found = glob($dir . '/' . $pattern) ?: [];
            foreach ($found as $file) {
                if (!is_file($file) || !jeab_reminders_backup_inside($file, $dirs)) {
                    continue;
                }
                $mtime = (int) filemtime($file);
                $name = basename($file);
                if ($mtime > $bestMtime || ($mtime === $bestMtime && strcmp($name, $bestName) > 0)) {
                    $bestPath = $file;
                    $bestMtime = $mtime;
                    $bestName = $name;
                }
            }
        }
    }
    if ($bestPath === '') {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($bestPath), true);
    if (!is_array($decoded)) {
        return [
            'file' => basename($bestPath),
            'mtime' => $bestMtime,
            'ids' => [],
            'booking_ids' => [],
        ];
    }
    $picked = jeab_reminders_ids_from_export($decoded);

    return [
        'file' => basename($bestPath),
        'mtime' => $bestMtime,
        'ids' => $picked['ids'],
        'booking_ids' => $picked['booking_ids'],
    ];
}

/**
 * Bara id. Inga namn, adresser eller anteckningar lämnar funktionen.
 *
 * @param array<string, mixed> $data
 * @return array{ids:array<int,true>,booking_ids:array<int,true>}
 */
function jeab_reminders_ids_from_export(array $data): array
{
    $ids = [];
    $bookingIds = [];
    $rows = $data['reminders'] ?? null;
    if (is_array($rows)) {
        foreach ($rows as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                $id = (int) $key;
            }
            if ($id <= 0) {
                continue;
            }
            $ids[$id] = true;
            if ((int) ($row['booking_id'] ?? 0) > 0) {
                $bookingIds[$id] = true;
            }
        }
    }
    $bookings = $data['bookings'] ?? null;
    if (is_array($bookings)) {
        foreach ($bookings as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['reminder_id'] ?? 0);
            if ($id > 0) {
                $bookingIds[$id] = true;
            }
        }
    }

    return ['ids' => $ids, 'booking_ids' => $bookingIds];
}

function jeab_reminders_sql_table(PDO $pdo, string $table): bool
{
    if (preg_match('/\A[a-z_]+\z/', $table) !== 1) {
        return false;
    }
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return true;
    }
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));

            return $stmt !== false && (bool) $stmt->fetchColumn();
        }
        $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table));

        return $stmt !== false && (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function jeab_reminders_sql_column(PDO $pdo, string $table, string $column): bool
{
    if (preg_match('/\A[a-z_]+\z/', $table) !== 1 || preg_match('/\A[a-z_]+\z/', $column) !== 1) {
        return false;
    }
    if (!jeab_reminders_sql_table($pdo, $table)) {
        return false;
    }
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return true;
    }
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($column));

            return $stmt !== false && (bool) $stmt->fetch();
        }
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
            if (is_array($row) && (string) ($row['name'] ?? '') === $column) {
                return true;
            }
        }
    } catch (Throwable) {
        return false;
    }

    return false;
}

/**
 * @return array{orders:array<int,true>,bookings:array<int,true>}
 */
function jeab_reminders_reference_ids(JeabStore $store): array
{
    $orders = [];
    $bookings = [];
    $noteOrder = static function (int $id, int $bookingId) use (&$orders, &$bookings): void {
        if ($id <= 0) {
            return;
        }
        $orders[$id] = true;
        if ($bookingId > 0) {
            $bookings[$id] = true;
        }
    };
    if ($store instanceof JeabPdoStore) {
        $pdo = $store->pdo();
        if (jeab_reminders_sql_table($pdo, 'purchases') && jeab_reminders_sql_column($pdo, 'purchases', 'reminder_id')) {
            try {
                $stmt = $pdo->query('SELECT reminder_id, payload FROM purchases');
                foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $payload = json_decode((string) ($row['payload'] ?? ''), true);
                    $bookingId = is_array($payload) ? (int) ($payload['booking_id'] ?? 0) : 0;
                    $noteOrder((int) ($row['reminder_id'] ?? 0), $bookingId);
                }
            } catch (Throwable) {
                $orders = [];
            }
        }
        if (jeab_reminders_sql_column($pdo, 'bookings', 'reminder_id')) {
            try {
                $stmt = $pdo->query('SELECT reminder_id FROM bookings WHERE reminder_id > 0');
                foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
                    $id = (int) (is_array($row) ? ($row['reminder_id'] ?? 0) : 0);
                    if ($id > 0) {
                        $bookings[$id] = true;
                    }
                }
            } catch (Throwable) {
                // Kolumnen gick inte att läsa. Bokningsreferensen från ordern står kvar.
            }
        }

        return ['orders' => $orders, 'bookings' => $bookings];
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $noteOrder((int) ($row['reminder_id'] ?? 0), (int) ($row['booking_id'] ?? 0));
        }
    }
    foreach ($store->listAllBookings() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? 0);
        if ($id > 0) {
            $bookings[$id] = true;
        }
    }

    return ['orders' => $orders, 'bookings' => $bookings];
}

/**
 * @param array<int, true> $live
 * @param array<int, true> $want
 * @return list<int>
 */
function jeab_reminders_missing_ids(array $live, array $want): array
{
    $missing = [];
    foreach (array_keys($want) as $id) {
        $id = (int) $id;
        if ($id > 0 && !isset($live[$id])) {
            $missing[] = $id;
        }
    }
    sort($missing);

    return $missing;
}

/**
 * Hook reminders.deleted_audit. Bara läsning.
 * Jämför id i den levande tabellen med nyaste JSON-kopian och med reminder_id i ordrar och bokningar.
 * all_backups=1 tar nyaste raden per id i alla kopior.
 * Svaret är id och antal. Ingen kundtext, ingen skrivning, inget utskick.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_deleted_audit(JeabStore $store, array $input = []): array
{
    $allBackups = jeab_reminders_restore_flag($input['all_backups'] ?? null);
    $live = [];
    $driver = 'json';
    if ($store instanceof JeabPdoStore) {
        $pdo = $store->pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        if (jeab_reminders_sql_table($pdo, 'reminders')) {
            foreach ($pdo->query('SELECT id FROM reminders') ?: [] as $row) {
                $id = (int) (is_array($row) ? ($row['id'] ?? 0) : 0);
                if ($id > 0) {
                    $live[$id] = true;
                }
            }
        }
    } else {
        foreach (jeab_reminders_every($store) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $live[$id] = true;
            }
        }
    }
    $backup = jeab_reminders_newest_backup();
    $scannedFiles = null;
    if ($allBackups) {
        $scan = jeab_reminders_restore_scan(null, false);
        $scannedFiles = (int) $scan['files'];
        $backupIds = [];
        $backupBookingIds = $scan['booking_ids'];
        foreach ($scan['newest'] as $id => $item) {
            $backupIds[(int) $id] = true;
            if ((int) ($item['booking_id'] ?? 0) > 0) {
                $backupBookingIds[(int) $id] = true;
            }
        }
    } else {
        $backupIds = is_array($backup) ? $backup['ids'] : [];
        $backupBookingIds = is_array($backup) ? $backup['booking_ids'] : [];
    }
    $refs = jeab_reminders_reference_ids($store);
    foreach (array_keys($backupBookingIds) as $id) {
        $refs['bookings'][(int) $id] = true;
    }
    $missingBackup = jeab_reminders_missing_ids($live, $backupIds);
    $missingOrders = jeab_reminders_missing_ids($live, $refs['orders']);
    $missingBookings = jeab_reminders_missing_ids($live, $refs['bookings']);
    $union = [];
    foreach ([$missingBackup, $missingOrders, $missingBookings] as $list) {
        foreach ($list as $id) {
            $union[(int) $id] = (int) $id;
        }
    }
    $missing = array_values($union);
    sort($missing);

    $result = [
        'ok' => true,
        'action' => 'reminders.deleted_audit',
        'read_only' => true,
        'dry' => 1,
        'driver' => $driver,
        'live_count' => count($live),
        'backup_file' => is_array($backup) ? (string) $backup['file'] : null,
        'backup_mtime' => is_array($backup) ? (int) $backup['mtime'] : null,
        'backup_count' => count($backupIds),
        'all_backups' => $allBackups ? 1 : 0,
        'orders_count' => count($refs['orders']),
        'bookings_ref_count' => count($refs['bookings']),
        'missing_backup' => $missingBackup,
        'missing_backup_count' => count($missingBackup),
        'missing_orders' => $missingOrders,
        'missing_orders_count' => count($missingOrders),
        'missing_bookings' => $missingBookings,
        'missing_bookings_count' => count($missingBookings),
        'missing' => $missing,
        'missing_count' => count($missing),
    ];
    if ($scannedFiles !== null) {
        $result['files'] = $scannedFiles;
    }

    return $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_deleted_audit_dispatch(JeabStore $store, array $input = []): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_reminders_deleted_audit($store, $input);
    $result['status'] = 200;

    return $result;
}

/**
 * Stamp for soft-sync: changes when visible set or any row payload changes.
 *
 * @param list<array<string, mixed>> $rows
 */
function jeab_reminder_list_stamp(array $rows): string
{
    $parts = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $parts[] = $id . ':' . (string) ($row['updated_at'] ?? '') . ':' . (string) ($row['completed_at'] ?? '')
            . ':' . (string) ($row['due_at'] ?? '') . ':' . (string) ($row['flagged'] ?? '')
            . ':' . md5((string) json_encode($row['tags'] ?? [], JSON_UNESCAPED_UNICODE));
    }
    return hash('sha256', implode('|', $parts));
}

/** Klientens liststämpel mot raderna. Tom eller annan längd räknas som skillnad. */
function jeab_reminder_sync_stamp_matches(string $clientStamp, array $rows): bool
{
    $clientStamp = trim($clientStamp);
    if ($clientStamp === '') {
        return false;
    }
    $serverStamp = jeab_reminder_list_stamp($rows);
    if (strlen($clientStamp) !== strlen($serverStamp)) {
        return false;
    }

    return hash_equals($serverStamp, $clientStamp);
}

/** Stämpel för ett kort. Byggs av fälten, inte av HTML, så sync kan hoppa över oförändrade rader. */
function jeab_reminder_row_stamp(array $row): string
{
    $id = (int) ($row['id'] ?? 0);
    $part = $id . ':' . (string) ($row['updated_at'] ?? '') . ':' . (string) ($row['completed_at'] ?? '')
        . ':' . (string) ($row['due_at'] ?? '') . ':' . (string) ($row['flagged'] ?? '')
        . ':' . md5((string) json_encode($row['tags'] ?? [], JSON_UNESCAPED_UNICODE));

    return substr(hash('sha256', $part), 0, 16);
}

/**
 * Klientens kortstämplar från rst=id.stamp,id.stamp. Bara id och 16 hex.
 *
 * @return array<int, string>
 */
function jeab_reminder_client_row_stamps(?string $raw = null): array
{
    $raw = $raw ?? (string) ($_GET['rst'] ?? '');
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 20000) {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $piece) {
        $piece = trim($piece);
        if (preg_match('/\A(\d{1,12})\.([0-9a-f]{16})\z/', $piece, $m) !== 1) {
            continue;
        }
        $out[(int) $m[1]] = $m[2];
    }

    return $out;
}

/**
 * Soft-sync payload for the current reminders view (no full page).
 *
 * @param list<array<string, mixed>> $visible
 * @param array{today?:int,scheduled?:int,all?:int,waiting?:int,done?:int} $counts
 * @return array{ok:bool,stamp:string,counts:array,items:list<array{id:int,stamp:string,html:string}>}
 */
function jeab_reminder_sync_payload(array $visible, array $counts, ?int $total = null, ?int $next = null): array
{
    jeab_reminder_arrived_preload($visible);
    $client = jeab_reminder_client_row_stamps();
    $items = [];
    $drawn = 0;
    foreach ($visible as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $stamp = jeab_reminder_row_stamp($row);
        $item = [
            'id' => $id,
            'stamp' => $stamp,
        ];
        $known = $client[$id] ?? '';
        $same = $known !== '' && hash_equals($known, $stamp);
        // Oförändrat kort ritas inte om. Nya och ändrade ritas, högst en sida.
        if (!$same && $drawn < JEAB_REMINDER_DONE_PAGE) {
            $item['html'] = jeab_reminder_row($row, false);
            $drawn++;
        }
        $items[] = $item;
    }

    return [
        'ok' => true,
        'stamp' => jeab_reminder_list_stamp($visible),
        'total' => $total ?? count($items),
        'next' => $next,
        'counts' => [
            'today' => (int) ($counts['today'] ?? 0),
            'scheduled' => (int) ($counts['scheduled'] ?? 0),
            'all' => (int) ($counts['all'] ?? 0),
            'waiting' => (int) ($counts['waiting'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
        ],
        'items' => $items,
    ];
}

/**
 * Bolagsvillkor som listan redan använder. Tomt bolag räknas som Järfälla.
 *
 * @return array{0:string,1:list<string>}
 */
function jeab_reminder_company_sql(string $company): array
{
    $company = jeab_normalize_company($company) ?? 'jarfalla';
    if ($company === 'jarfalla') {
        return ["(company = ? OR company IS NULL OR company = '')", [$company]];
    }

    return ['company = ?', [$company]];
}

/**
 * Vypredikatet är samma som jeab_filter_reminders utan sök.
 *
 * @return array{0:string,1:list<string>}
 */
function jeab_reminder_view_sql(string $view, string $today, string $now): array
{
    $open = "(completed_at IS NULL OR completed_at = '')";
    $due = "(due_at IS NOT NULL AND due_at != '')";

    return match ($view) {
        // due_at <= nu räcker: ett senare datum är också senare än nu. substr() kan inte använda index.
        'today' => [$open . ' AND ' . $due . ' AND due_at <= ?', [$now]],
        'scheduled' => [$open . ' AND ' . $due, []],
        'waiting', 'flagged' => [$open . ' AND ' . $due . ' AND due_at > ?', [$now]],
        'done' => ['NOT ' . $open, []],
        default => ['1', []],
    };
}

/**
 * @param array{mode?:string,tag?:string|null} $tag
 * @return array{0:string,1:list<string>}
 */
function jeab_reminder_tag_sql(PDO $pdo, array $tag): array
{
    $mode = jeab_reminder_tag_mode($tag);
    if ($mode['mode'] === 'all') {
        return ['1', []];
    }
    if ($mode['mode'] === 'none') {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $emptyJson = $driver === 'mysql'
            ? "(JSON_VALID(tags) AND JSON_LENGTH(tags) = 0)"
            : "(json_valid(tags) AND NOT EXISTS (SELECT 1 FROM json_each(tags)))";

        return ['(tags IS NULL OR TRIM(tags) = \'\' OR tags = \'[]\' OR ' . $emptyJson . ')', []];
    }
    $want = (string) ($mode['tag'] ?? '');
    if ($want === '') {
        return ['1', []];
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $sql = "JSON_SEARCH(LOWER(IF(JSON_VALID(tags), tags, '[]')), 'one', LOWER(?), NULL, '$') IS NOT NULL";
    } else {
        $sql = "EXISTS (SELECT 1 FROM json_each(CASE WHEN json_valid(tags) THEN tags ELSE '[]' END) WHERE lower(value) = lower(?))";
    }

    return [$sql, [$want]];
}

/** Samma ordning som jeab_filter_reminders efter listans grundordning. */
function jeab_reminder_page_order_sql(string $view): string
{
    $open = "(completed_at IS NULL OR completed_at = '')";
    $base = 'CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END,
            CASE WHEN due_at IS NULL THEN 1 ELSE 0 END,
            due_at ASC,
            created_at DESC';
    if ($view === 'done') {
        return 'completed_at DESC, id DESC';
    }
    if ($view === 'today' || $view === 'scheduled' || $view === 'waiting' || $view === 'flagged') {
        // WHERE har redan tagit bort klara rader och rader utan due_at.
        // CASE i ORDER BY tvingar filsortering även när reminders_tally
        // eller reminders_list_open kan leverera due_at-ordningen.
        return 'due_at ASC, created_at DESC';
    }
    if ($view === 'all') {
        return 'CASE WHEN ' . $open . ' THEN 0 ELSE 1 END,
            CASE WHEN ' . $open . ' THEN CASE WHEN due_at IS NULL THEN 1 ELSE 0 END ELSE 0 END,
            CASE WHEN ' . $open . ' THEN due_at ELSE NULL END ASC,
            CASE WHEN ' . $open . ' THEN created_at ELSE NULL END DESC,
            CASE WHEN ' . $open . ' THEN NULL ELSE completed_at END DESC,
            CASE WHEN ' . $open . ' THEN 0 ELSE id END DESC';
    }

    return $base;
}

/**
 * WHERE för bolag, vy, person och tagg. Sök ingår inte: den läser kontaktens namn.
 *
 * @param array{mode?:string,tag?:string|null} $tag
 * @return array{0:string,1:list<string>}
 */
function jeab_reminders_sql_where(PDO $pdo, int $listId, string $company, ?string $who, string $view, array $tag, string $today, string $now): array
{
    [$companySql, $companyArgs] = jeab_reminder_company_sql($company);
    [$viewSql, $viewArgs] = jeab_reminder_view_sql($view, $today, $now);
    [$tagSql, $tagArgs] = jeab_reminder_tag_sql($pdo, $tag);
    $sql = 'list_id = ? AND id > 0 AND ' . $companySql . ' AND (' . $viewSql . ') AND (' . $tagSql . ')';
    $args = array_merge([$listId], $companyArgs, $viewArgs, $tagArgs);
    if ($who !== null && $who !== '') {
        [$whoSql, $whoArgs] = jeab_reminder_assignee_sql($pdo, $who, $company);
        $sql .= ' AND (' . $whoSql . ')';
        array_push($args, ...$whoArgs);
    }

    return [$sql, $args];
}

function jeab_reminders_sql_list_id(PDO $pdo): int
{
    $key = spl_object_id($pdo);
    if (isset($GLOBALS['_JEAB_REMINDER_LIST_ID'][$key])) {
        return (int) $GLOBALS['_JEAB_REMINDER_LIST_ID'][$key];
    }
    $id = $pdo->query('SELECT id FROM reminder_lists ORDER BY id ASC LIMIT 1')->fetchColumn();
    $id = (int) $id;
    if ($id > 0) {
        if (!isset($GLOBALS['_JEAB_REMINDER_LIST_ID']) || !is_array($GLOBALS['_JEAB_REMINDER_LIST_ID'])) {
            $GLOBALS['_JEAB_REMINDER_LIST_ID'] = [];
        }
        $GLOBALS['_JEAB_REMINDER_LIST_ID'][$key] = $id;
    }

    return $id;
}

/**
 * Alla träffande id i samma ordning som PHP-filtret. Används av testet och
 * när ett öppet kort ligger längre ner än första sidan.
 *
 * @param array{mode?:string,tag?:string|null} $tag
 * @return list<int>
 */
function jeab_reminders_sql_ids(JeabPdoStore $store, string $company, ?string $who, string $view, array $tag, ?string $today = null, ?string $now = null): array
{
    $pdo = $store->pdo();
    $listId = jeab_reminders_sql_list_id($pdo);
    if ($listId <= 0) {
        return [];
    }
    $today ??= (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
    $now ??= jeab_now();
    [$where, $args] = jeab_reminders_sql_where($pdo, $listId, $company, $who, $view, $tag, $today, $now);
    $order = jeab_reminder_page_order_sql($view);
    $stmt = $pdo->prepare('SELECT id FROM reminders WHERE ' . $where . ' ORDER BY ' . $order);
    $stmt->execute($args);
    $ids = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) (is_array($row) ? ($row['id'] ?? 0) : 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * Öppna rader för vyn Alla, samma ordning som listan. null när sidan också
 * behöver klara rader: då får den vanliga frågan ta vid.
 *
 * @param array{mode?:string,tag?:string|null} $tag
 * @return list<int>|null
 */
function jeab_reminders_sql_open_page_ids(JeabPdoStore $store, string $company, ?string $who, array $tag, int $offset, int $limit, ?string $today = null, ?string $now = null): ?array
{
    if ($limit <= 0) {
        return null;
    }
    $pdo = $store->pdo();
    $listId = jeab_reminders_sql_list_id($pdo);
    if ($listId <= 0) {
        return null;
    }
    $today ??= (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
    $now ??= jeab_now();
    [$where, $args] = jeab_reminders_sql_where($pdo, $listId, $company, $who, 'all', $tag, $today, $now);
    $where .= " AND (completed_at IS NULL OR completed_at = '')";
    $order = 'CASE WHEN due_at IS NULL THEN 1 ELSE 0 END, due_at ASC, created_at DESC';
    $sql = 'SELECT id FROM reminders WHERE ' . $where . ' ORDER BY ' . $order
        . jeab_sql_limit_clause($limit, max(0, $offset));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $ids = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) (is_array($row) ? ($row['id'] ?? 0) : 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if (count($ids) < $limit) {
        return null;
    }

    return $ids;
}

/**
 * Första sidan (eller Visa fler) med LIMIT. Samma id och ordning som
 * array_slice av jeab_reminders_sql_ids.
 *
 * @param array{mode?:string,tag?:string|null} $tag
 * @return list<int>
 */
function jeab_reminders_sql_page_ids(JeabPdoStore $store, string $company, ?string $who, string $view, array $tag, int $offset, int $limit, ?string $today = null, ?string $now = null): array
{
    if ($limit <= 0) {
        return [];
    }
    if ($view === 'all') {
        $openPage = jeab_reminders_sql_open_page_ids($store, $company, $who, $tag, $offset, $limit, $today, $now);
        if ($openPage !== null) {
            return $openPage;
        }
    }
    $pdo = $store->pdo();
    $listId = jeab_reminders_sql_list_id($pdo);
    if ($listId <= 0) {
        return [];
    }
    $today ??= (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
    $now ??= jeab_now();
    [$where, $args] = jeab_reminders_sql_where($pdo, $listId, $company, $who, $view, $tag, $today, $now);
    $order = jeab_reminder_page_order_sql($view);
    $sql = 'SELECT id FROM reminders WHERE ' . $where . ' ORDER BY ' . $order
        . jeab_sql_limit_clause($limit, max(0, $offset));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $ids = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) (is_array($row) ? ($row['id'] ?? 0) : 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * @param list<int> $ids
 * @return list<array<string, mixed>>
 */
function jeab_reminders_sql_rows_for_ids(JeabPdoStore $store, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $found = $store->reminderRowsByIds($ids);
    $out = [];
    foreach ($ids as $id) {
        $row = $found[$id] ?? null;
        if (!is_array($row)) {
            continue;
        }
        unset($row['note']);
        $row['_jeab_list_slim'] = true;
        $out[] = $row;
    }

    return $out;
}

function jeab_reminders_count_cache_ttl(): int
{
    if (isset($GLOBALS['_JEAB_REMINDER_COUNT_TTL'])) {
        return max(0, (int) $GLOBALS['_JEAB_REMINDER_COUNT_TTL']);
    }

    return JEAB_REMINDER_COUNT_TTL;
}

function jeab_reminders_count_cache_dir(): string
{
    return rtrim(sys_get_temp_dir(), '/') . '/jeab-reminder-counts';
}

function jeab_reminders_count_cache_scope(PDO $pdo): string
{
    $key = spl_object_id($pdo);
    if (isset($GLOBALS['_JEAB_REMINDER_COUNT_SCOPE'][$key]) && is_string($GLOBALS['_JEAB_REMINDER_COUNT_SCOPE'][$key])) {
        return $GLOBALS['_JEAB_REMINDER_COUNT_SCOPE'][$key];
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $scope = $driver;
    try {
        if ($driver === 'mysql') {
            $scope .= ':' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        } else {
            $row = $pdo->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
            $scope .= ':' . (is_array($row) ? (string) ($row['file'] ?? '') : '');
        }
    } catch (Throwable) {
        $scope .= ':' . $key;
    }
    if (!isset($GLOBALS['_JEAB_REMINDER_COUNT_SCOPE']) || !is_array($GLOBALS['_JEAB_REMINDER_COUNT_SCOPE'])) {
        $GLOBALS['_JEAB_REMINDER_COUNT_SCOPE'] = [];
    }
    $GLOBALS['_JEAB_REMINDER_COUNT_SCOPE'][$key] = $scope;

    return $scope;
}

function jeab_reminders_count_cache_file(string $scope): string
{
    return jeab_reminders_count_cache_dir() . '/' . hash('sha256', $scope) . '.json';
}

/** @return array<string, array{t:int,counts:array<string,int>,who:array<string,int>}> */
function jeab_reminders_count_cache_bag(string $scope): array
{
    if (isset($GLOBALS['_JEAB_REMINDER_COUNT_LOADED'][$scope])) {
        $mem = $GLOBALS['_JEAB_REMINDER_COUNT_MEM'][$scope] ?? [];

        return is_array($mem) ? $mem : [];
    }
    if (!isset($GLOBALS['_JEAB_REMINDER_COUNT_LOADED']) || !is_array($GLOBALS['_JEAB_REMINDER_COUNT_LOADED'])) {
        $GLOBALS['_JEAB_REMINDER_COUNT_LOADED'] = [];
    }
    $GLOBALS['_JEAB_REMINDER_COUNT_LOADED'][$scope] = true;
    $bag = [];
    $raw = @file_get_contents(jeab_reminders_count_cache_file($scope));
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($data)) {
        foreach ($data as $key => $row) {
            if (!is_string($key) || !is_array($row) || !is_array($row['counts'] ?? null)) {
                continue;
            }
            $bag[$key] = $row;
        }
    }
    if (!isset($GLOBALS['_JEAB_REMINDER_COUNT_MEM']) || !is_array($GLOBALS['_JEAB_REMINDER_COUNT_MEM'])) {
        $GLOBALS['_JEAB_REMINDER_COUNT_MEM'] = [];
    }
    $GLOBALS['_JEAB_REMINDER_COUNT_MEM'][$scope] = $bag;

    return $bag;
}

/** @param array<string, array{t:int,counts:array<string,int>,who:array<string,int>}> $bag */
function jeab_reminders_count_cache_store(string $scope, array $bag): void
{
    if (!isset($GLOBALS['_JEAB_REMINDER_COUNT_MEM']) || !is_array($GLOBALS['_JEAB_REMINDER_COUNT_MEM'])) {
        $GLOBALS['_JEAB_REMINDER_COUNT_MEM'] = [];
    }
    $GLOBALS['_JEAB_REMINDER_COUNT_MEM'][$scope] = $bag;
    if (!isset($GLOBALS['_JEAB_REMINDER_COUNT_LOADED']) || !is_array($GLOBALS['_JEAB_REMINDER_COUNT_LOADED'])) {
        $GLOBALS['_JEAB_REMINDER_COUNT_LOADED'] = [];
    }
    $GLOBALS['_JEAB_REMINDER_COUNT_LOADED'][$scope] = true;
    $dir = jeab_reminders_count_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    @file_put_contents(
        jeab_reminders_count_cache_file($scope),
        json_encode($bag, JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/** Tömmer räknarcachen. Anropas när en påminnelse skrivs. */
function jeab_reminders_count_cache_forget(?PDO $pdo = null): void
{
    $GLOBALS['_JEAB_REMINDER_COUNT_MEM'] = [];
    $GLOBALS['_JEAB_REMINDER_COUNT_LOADED'] = [];
    if ($pdo instanceof PDO) {
        $file = jeab_reminders_count_cache_file(jeab_reminders_count_cache_scope($pdo));
        if (is_file($file)) {
            @unlink($file);
        }

        return;
    }
    $dir = jeab_reminders_count_cache_dir();
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }
}

/** Namnet på den inloggades Mina-knapp, eller tomt. */
function jeab_reminders_sql_mina_name(string $company): string
{
    $company = jeab_normalize_company($company) ?? 'jarfalla';
    $names = jeab_reminder_who_options($company, []);
    // Samma person som Egna i Inkorg: inloggningen, annars sessionens kodperson.
    $me = trim((string) (jeab_reminder_mina_person()['name'] ?? ''));
    if ($me === '') {
        $fallback = jeab_reminder_who_me_name($company);
        $me = $fallback !== null ? trim($fallback) : '';
    }
    foreach ($names as $name) {
        if ($me !== '' && strcasecmp($name, $me) === 0) {
            return $name;
        }
    }
    if ($me === '') {
        return '';
    }
    $record = jeab_reminder_person_record($me, $company);
    if (!is_array($record)) {
        $record = jeab_reminder_person_record($me, null);
    }
    if (is_array($record)) {
        $canon = trim((string) ($record['name'] ?? ''));
        if ($canon !== '') {
            return $canon;
        }
    }

    return $me;
}

/**
 * diag.mina. Identitet, Mina-namn och träffar per fält. Ingen e-post, inget påminnelseinnehåll
 * och högst nio tecken av en kampanjkod.
 *
 * @param array<string, mixed> $input person eller email simulerar inloggningen
 * @return array<string, mixed>
 */
function jeab_diag_mina(array $input = []): array
{
    $prevForce = $GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE'] ?? null;
    $prevIdent = $GLOBALS['_JEAB_LOGIN_IDENTITY'] ?? null;
    if (($input['person'] ?? '') !== '' || ($input['person_id'] ?? '') !== '' || ($input['email'] ?? '') !== '') {
        if (function_exists('jeab_login_identity_simulate')) {
            jeab_login_identity_simulate($input);
        }
    }
    try {
        return jeab_diag_mina_body();
    } finally {
        if ($prevForce === null) {
            unset($GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE']);
        } else {
            $GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE'] = $prevForce;
        }
        if ($prevIdent === null) {
            unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);
        } else {
            $GLOBALS['_JEAB_LOGIN_IDENTITY'] = $prevIdent;
        }
    }
}

/** @return array<string, mixed> */
function jeab_diag_mina_body(): array
{
    $identity = function_exists('jeab_login_identity')
        ? jeab_login_identity(true, false)
        : ['certain' => false, 'person' => null, 'reason' => 'none'];
    $person = is_array($identity['person'] ?? null) ? $identity['person'] : null;
    $who = function_exists('jeab_who_me_name') ? trim(jeab_who_me_name()) : '';
    $mina = [];
    foreach (['jarfalla', 'malmo'] as $company) {
        $mina[$company] = jeab_reminders_sql_mina_name($company);
    }
    $heads = [];
    $rows = function_exists('jeab_code_people_read') ? jeab_code_people_read(false) : [];
    if (is_array($rows)) {
        foreach ($rows as $key => $row) {
            if (count($heads) >= 12 || !is_array($row)) {
                continue;
            }
            $via = trim((string) ($row['via'] ?? ''));
            if (str_contains($via, '@')) {
                $via = 'email';
            }
            $heads[] = [
                'person' => trim((string) ($row['person'] ?? '')),
                'via' => $via,
                'head' => jeab_diag_mina_head($row, (string) $key),
            ];
        }
    }
    $needles = [];
    $add = static function (string $value) use (&$needles): void {
        $value = trim($value);
        if ($value === '' || count($needles) >= 8) {
            return;
        }
        foreach ($needles as $have) {
            if (strcasecmp($have, $value) === 0) {
                return;
            }
        }
        $needles[] = $value;
    };
    if (is_array($person)) {
        $add((string) ($person['id'] ?? ''));
        $add((string) ($person['name'] ?? ''));
        jeab_diag_mina_add_configured($person, $add);
    }
    $add($who);
    foreach ($mina as $name) {
        $add($name);
    }
    foreach ($heads as $head) {
        $add((string) $head['person']);
        if (!function_exists('jeab_reminder_person_record')) {
            continue;
        }
        $record = jeab_reminder_person_record((string) $head['person'], null);
        if (!is_array($record)) {
            continue;
        }
        $add((string) ($record['id'] ?? ''));
        $add((string) ($record['name'] ?? ''));
        jeab_diag_mina_add_configured($record, $add);
    }
    $hits = [];
    $pdo = null;
    if (function_exists('jeab_store')) {
        try {
            $store = jeab_store();
            if (is_object($store) && method_exists($store, 'pdo')) {
                $got = $store->pdo();
                $pdo = $got instanceof PDO ? $got : null;
            }
        } catch (Throwable) {
            $pdo = null;
        }
    }
    if (!$pdo instanceof PDO && function_exists('jeab_data_pdo')) {
        $pdo = jeab_data_pdo();
    }
    $aliases = is_array($person) && function_exists('jeab_person_aliases') ? jeab_person_aliases($person) : [];
    foreach (['jarfalla', 'malmo'] as $company) {
        $hits[$company] = [];
        foreach ($needles as $needle) {
            $hits[$company][$needle] = $pdo instanceof PDO
                ? jeab_diag_mina_hit($pdo, $company, $needle)
                : ['person' => 0, 'assignee' => 0, 'assignees' => 0, 'ms' => 0, 'error' => 'Ingen SQL-databas.'];
        }
    }
    $stored = [];
    $page = [];
    foreach (['jarfalla', 'malmo'] as $company) {
        $page[$company] = $pdo instanceof PDO
            ? jeab_diag_mina_page_count($pdo, $company, (string) ($mina[$company] ?? ''))
            : 0;
        $stored[$company] = $pdo instanceof PDO
            ? jeab_diag_mina_stored($pdo, $company, $aliases)
            : [];
    }

    return [
        'ok' => true,
        'identity' => [
            'certain' => !empty($identity['certain']),
            'reason' => (string) ($identity['reason'] ?? 'none'),
            'person_id' => is_array($person) ? trim((string) ($person['id'] ?? '')) : '',
            'name' => is_array($person) ? trim((string) ($person['name'] ?? '')) : '',
        ],
        'who_me_name' => $who,
        'mina_name' => $mina,
        'aliases' => array_values(array_filter($aliases, static fn (string $alias): bool => !str_contains($alias, '@'))),
        'page_mina' => $page,
        'stored' => $stored,
        'code_heads' => $heads,
        'hits' => $hits,
    ];
}

/**
 * Samma villkor som chippet Mina. Inget påminnelseinnehåll.
 */
function jeab_diag_mina_page_count(PDO $pdo, string $company, string $mina): int
{
    $mina = trim($mina);
    if ($mina === '' || !function_exists('jeab_reminder_assignee_sql') || !function_exists('jeab_reminder_company_sql')) {
        return 0;
    }
    try {
        [$whoSql, $whoArgs] = jeab_reminder_assignee_sql($pdo, $mina, $company);
        [$companySql, $companyArgs] = jeab_reminder_company_sql($company);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM reminders WHERE id > 0 AND ' . $companySql . ' AND (' . $whoSql . ')');
        $stmt->execute(array_merge($companyArgs, $whoArgs));

        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Värden i person, assignee och assignees som delar alias med den inloggade.
 * En e-postadress visas som «email», inte som adressen.
 *
 * @param list<string> $aliases
 * @return list<array{field:string,value:string,n:int}>
 */
function jeab_diag_mina_stored(PDO $pdo, string $company, array $aliases): array
{
    $tokens = [];
    foreach ($aliases as $alias) {
        $alias = trim((string) $alias);
        if ($alias === '' || str_contains($alias, '@') || mb_strlen($alias) < 3) {
            continue;
        }
        $tokens[mb_strtolower($alias)] = true;
    }
    if ($tokens === []) {
        return [];
    }
    $companies = $company === 'malmo' ? ['malmo', 'meab'] : [$company];
    $marks = implode(',', array_fill(0, count($companies), '?'));
    $out = [];
    $push = static function (string $field, string $value, int $n) use (&$out, $tokens): void {
        if (count($out) >= 12 || $value === '' || $n <= 0) {
            return;
        }
        $shown = str_contains($value, '@') ? 'email' : mb_substr($value, 0, 80);
        $fold = mb_strtolower($value);
        $hit = false;
        foreach (array_keys($tokens) as $token) {
            if ($fold === $token || str_contains($fold, $token)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return;
        }
        foreach ($out as $have) {
            if ($have['field'] === $field && strcasecmp($have['value'], $shown) === 0) {
                return;
            }
        }
        $out[] = ['field' => $field, 'value' => $shown, 'n' => $n];
    };
    try {
        foreach (['person', 'assignee'] as $field) {
            $stmt = $pdo->prepare(
                'SELECT ' . $field . ' AS v, COUNT(*) AS n FROM reminders WHERE id > 0 AND company IN (' . $marks . ') AND TRIM(IFNULL(' . $field . ", '')) <> '' GROUP BY " . $field . ' ORDER BY n DESC LIMIT 40'
            );
            $stmt->execute($companies);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $push($field, trim((string) ($row['v'] ?? '')), (int) ($row['n'] ?? 0));
            }
        }
        $stmt = $pdo->prepare(
            'SELECT assignees AS v, COUNT(*) AS n FROM reminders WHERE id > 0 AND company IN (' . $marks . ") AND TRIM(IFNULL(assignees, '')) <> '' GROUP BY assignees ORDER BY n DESC LIMIT 40"
        );
        $stmt->execute($companies);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = trim((string) ($row['v'] ?? ''));
            $decoded = json_decode($raw, true);
            $parts = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $raw);
            if (!is_array($parts)) {
                continue;
            }
            foreach ($parts as $part) {
                if (is_string($part)) {
                    $push('assignees', trim($part), (int) ($row['n'] ?? 0));
                }
            }
        }
    } catch (Throwable) {
        return $out;
    }

    return $out;
}

/** @param array<string, mixed> $row */
function jeab_diag_mina_head(array $row, string $key): string
{
    $stored = trim((string) ($row['head'] ?? ''));
    $raw = $stored !== '' ? $stored : $key;
    $raw = preg_replace('/^fp:/i', '', $raw) ?? $raw;
    $head = function_exists('jeab_code_head') ? jeab_code_head($raw) : substr($raw, 0, 9);

    return mb_substr($head, 0, 9);
}

/** @param array<string, mixed> $row @param callable(string): void $add */
function jeab_diag_mina_add_configured(array $row, callable $add): void
{
    foreach (['alias', 'aliases'] as $field) {
        $value = $row[$field] ?? null;
        $parts = is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value);
        if (!is_array($parts)) {
            continue;
        }
        foreach ($parts as $part) {
            $add((string) $part);
        }
    }
}

/** @return array{person:int, assignee:int, assignees:int, ms:int, error:string} */
function jeab_diag_mina_hit(PDO $pdo, string $company, string $needle): array
{
    $t0 = microtime(true);
    $companies = $company === 'malmo' ? ['malmo', 'meab'] : [$company];
    $marks = implode(',', array_fill(0, count($companies), '?'));
    $fail = static function (string $error) use ($t0): array {
        return [
            'person' => 0,
            'assignee' => 0,
            'assignees' => 0,
            'ms' => (int) round((microtime(true) - $t0) * 1000),
            'error' => mb_substr($error, 0, 180),
        ];
    };
    try {
        $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) {
            $person = "LOWER(IFNULL(person, '')) = LOWER(?)";
            $assignee = "INSTR(LOWER(CONCAT(',', REPLACE(IFNULL(assignee, ''), ', ', ','), ',')), CONCAT(',', LOWER(?), ',')) > 0";
            $assignees = "INSTR(LOWER(IFNULL(assignees, '')), LOWER(?)) > 0";
        } else {
            $person = "lower(IFNULL(person, '')) = lower(?)";
            $assignee = "instr(lower(',' || replace(IFNULL(assignee, ''), ', ', ',') || ','), ',' || lower(?) || ',') > 0";
            $assignees = "instr(lower(IFNULL(assignees, '')), lower(?)) > 0";
        }
        $stmt = $pdo->prepare(
            'SELECT
                SUM(CASE WHEN ' . $person . ' THEN 1 ELSE 0 END) AS person,
                SUM(CASE WHEN ' . $assignee . ' THEN 1 ELSE 0 END) AS assignee,
                SUM(CASE WHEN ' . $assignees . ' THEN 1 ELSE 0 END) AS assignees
             FROM reminders WHERE id > 0 AND company IN (' . $marks . ')'
        );
        $stmt->execute(array_merge([$needle, $needle, '"' . $needle . '"'], $companies));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = is_array($row) ? $row : [];

        return [
            'person' => (int) ($row['person'] ?? 0),
            'assignee' => (int) ($row['assignee'] ?? 0),
            'assignees' => (int) ($row['assignees'] ?? 0),
            'ms' => (int) round((microtime(true) - $t0) * 1000),
            'error' => '',
        ];
    } catch (Throwable $e) {
        $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';

        return $fail(trim($message));
    }
}

/**
 * Fem flikar, och vid behov Alla plus Mina, i ett svep.
 *
 * @param array{mode?:string,tag?:string|null} $tag
 * @return array{counts:array<string,int>,who:array<string,int>}
 */
function jeab_reminders_sql_tally(JeabPdoStore $store, string $company, ?string $who, string $view, array $tag, ?string $today = null, ?string $now = null, bool $withWho = false): array
{
    $company = jeab_normalize_company($company) ?? 'jarfalla';
    $who = $who !== null ? trim($who) : '';
    $tag = jeab_reminder_tag_mode($tag);
    $today ??= (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
    $now ??= jeab_now();
    $empty = [
        'counts' => ['today' => 0, 'scheduled' => 0, 'all' => 0, 'waiting' => 0, 'done' => 0],
        'who' => [],
    ];
    $mina = jeab_reminders_sql_mina_name($company);
    $emptyWho = ['all' => 0];
    if ($mina !== '') {
        $emptyWho[$mina] = 0;
    }
    $empty['who'] = $withWho ? $emptyWho : [];
    $pdo = $store->pdo();
    // Vyn ingår inte. Filterbyte och öppet kort läser samma post.
    $cacheKey = implode("\0", [
        $company,
        $who,
        (string) $tag['mode'],
        (string) ($tag['tag'] ?? ''),
        $mina,
        $today,
        'v2',
    ]);
    $scope = jeab_reminders_count_cache_scope($pdo);
    $ttl = jeab_reminders_count_cache_ttl();
    $fromBundle = static function (array $hit) use ($empty, $view, $withWho, $mina): array {
        $counts = $empty['counts'];
        foreach ($counts as $name => $_) {
            $counts[$name] = (int) ($hit['counts'][$name] ?? 0);
        }
        $whoOut = $withWho ? ['all' => 0] : [];
        if ($withWho && $mina !== '') {
            $whoOut[$mina] = 0;
        }
        $byView = is_array($hit['who_by_view'] ?? null) ? $hit['who_by_view'] : [];
        $lookup = $view === 'flagged' ? 'waiting' : $view;
        $one = is_array($byView[$lookup] ?? null) ? $byView[$lookup] : [];
        if ($withWho) {
            $whoOut['all'] = (int) ($one['all'] ?? 0);
            if ($mina !== '') {
                $whoOut[$mina] = (int) ($one[$mina] ?? 0);
            }
        }

        return ['counts' => $counts, 'who' => $whoOut];
    };
    if ($ttl > 0) {
        $bag = jeab_reminders_count_cache_bag($scope);
        $hit = $bag[$cacheKey] ?? null;
        if (is_array($hit) && (time() - (int) ($hit['t'] ?? 0)) < $ttl && is_array($hit['counts'] ?? null) && is_array($hit['who_by_view'] ?? null)) {
            return $fromBundle($hit);
        }
    }
    $listId = jeab_reminders_sql_list_id($pdo);
    if ($listId <= 0) {
        return $empty;
    }
    [$companySql, $companyArgs] = jeab_reminder_company_sql($company);
    [$tagSql, $tagArgs] = jeab_reminder_tag_sql($pdo, $tag);
    $whoSql = '1';
    $whoArgs = [];
    if ($who !== '') {
        [$whoSql, $whoArgs] = jeab_reminder_assignee_sql($pdo, $who, $company);
    }
    $minaSql = '0';
    $minaArgs = [];
    if ($mina !== '') {
        [$minaSql, $minaArgs] = jeab_reminder_assignee_sql($pdo, $mina, $company);
    }
    $flags = [];
    $args = [];
    foreach (array_keys($empty['counts']) as $oneView) {
        [$viewSql, $viewArgs] = jeab_reminder_view_sql($oneView, $today, $now);
        $flags[] = 'CASE WHEN (' . $viewSql . ') THEN 1 ELSE 0 END AS v_' . $oneView;
        array_push($args, ...$viewArgs);
    }
    $flags[] = 'CASE WHEN (' . $whoSql . ') THEN 1 ELSE 0 END AS who_hit';
    array_push($args, ...$whoArgs);
    $minaSame = $mina !== '' && $minaSql === $whoSql && $minaArgs === $whoArgs;
    if ($mina !== '' && !$minaSame) {
        $flags[] = 'CASE WHEN (' . $minaSql . ') THEN 1 ELSE 0 END AS mina_hit';
        array_push($args, ...$minaArgs);
    }
    $inner = 'SELECT ' . implode(', ', $flags)
        . ' FROM reminders WHERE list_id = ? AND id > 0 AND ' . $companySql . ' AND (' . $tagSql . ')';
    array_push($args, $listId, ...$companyArgs, ...$tagArgs);
    $outer = [];
    foreach (array_keys($empty['counts']) as $oneView) {
        $outer[] = 'COALESCE(SUM(CASE WHEN v_' . $oneView . ' = 1 AND who_hit = 1 THEN 1 ELSE 0 END), 0) AS c_' . $oneView;
        $outer[] = 'COALESCE(SUM(CASE WHEN v_' . $oneView . ' = 1 THEN 1 ELSE 0 END), 0) AS w_' . $oneView . '_all';
        if ($mina !== '') {
            $minaCol = $minaSame ? 'who_hit' : 'mina_hit';
            $outer[] = 'COALESCE(SUM(CASE WHEN v_' . $oneView . ' = 1 AND ' . $minaCol . ' = 1 THEN 1 ELSE 0 END), 0) AS w_' . $oneView . '_mina';
        }
    }
    $sql = 'SELECT ' . implode(', ', $outer) . ' FROM (' . $inner . ') jeab_tally';
    $GLOBALS['_JEAB_REMINDER_TALLY_LAST_SQL'] = $sql;
    $started = microtime(true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('rem_tally', microtime(true) - $started);
    }
    $GLOBALS['_JEAB_REMINDER_TALLY_SQL'] = (int) ($GLOBALS['_JEAB_REMINDER_TALLY_SQL'] ?? 0) + 1;
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $row = is_array($row) ? $row : [];
    $whoByView = [];
    foreach ($empty['counts'] as $name => $_) {
        $empty['counts'][$name] = (int) ($row['c_' . $name] ?? 0);
        $whoByView[$name] = ['all' => (int) ($row['w_' . $name . '_all'] ?? 0)];
        if ($mina !== '') {
            $whoByView[$name][$mina] = (int) ($row['w_' . $name . '_mina'] ?? 0);
        }
    }
    if ($ttl > 0) {
        $bag = jeab_reminders_count_cache_bag($scope);
        $bag[$cacheKey] = [
            't' => time(),
            'counts' => $empty['counts'],
            'who_by_view' => $whoByView,
        ];
        jeab_reminders_count_cache_store($scope, $bag);
    }

    return $fromBundle([
        'counts' => $empty['counts'],
        'who_by_view' => $whoByView,
    ]);
}

/**
 * @param array{mode?:string,tag?:string|null} $tag
 * @return array<string, int>
 */
function jeab_reminders_sql_counts(JeabPdoStore $store, string $company, ?string $who, array $tag, ?string $today = null, ?string $now = null): array
{
    return jeab_reminders_sql_tally($store, $company, $who, 'today', $tag, $today, $now, false)['counts'];
}

/**
 * Alla och den inloggades Mina. Övriga personer målas inte, så de räknas inte.
 *
 * @param array{mode?:string,tag?:string|null} $tag
 * @return array<string, int>
 */
function jeab_reminders_sql_who_counts(JeabPdoStore $store, string $company, string $view, array $tag, ?string $today = null, ?string $now = null): array
{
    return jeab_reminders_sql_tally($store, $company, null, $view, $tag, $today, $now, true)['who'];
}

/**
 * Finns id i samma urval som sidan? Primärnyckel, inget listsvep.
 *
 * @param array{mode?:string,tag?:string|null} $tag
 */
function jeab_reminders_sql_has_id(JeabPdoStore $store, int $id, string $company, ?string $who, string $view, array $tag, string $today, string $now): bool
{
    if ($id <= 0) {
        return false;
    }
    $pdo = $store->pdo();
    $listId = jeab_reminders_sql_list_id($pdo);
    if ($listId <= 0) {
        return false;
    }
    [$where, $args] = jeab_reminders_sql_where($pdo, $listId, $company, $who, $view, $tag, $today, $now);
    $stmt = $pdo->prepare('SELECT 1 FROM reminders WHERE id = ? AND ' . $where . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $args));

    return (int) $stmt->fetchColumn() === 1;
}

/**
 * Sidan utan sök. LIMIT sitter i SQL. Ett öppet kort utanför sidan hämtas
 * för sig och läggs sist, så sidan inte växer till kortets plats.
 *
 * @param array{mode?:string,tag?:string|null} $tag
 * @return array{rows:list<array<string,mixed>>,total:int,offset:int,next:?int,counts:array<string,int>,who:array<string,int>}
 */
function jeab_reminders_sql_pack(JeabPdoStore $store, string $company, ?string $who, string $view, array $tag, int $offset, int $limit, int $openId = 0, bool $withWho = true): array
{
    $today = (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
    $now = jeab_now();
    $offset = max(0, $offset);
    $limit = max(JEAB_REMINDER_DONE_PAGE, min($limit, 1000));
    $tally = jeab_reminders_sql_tally($store, $company, $who, $view, $tag, $today, $now, $withWho);
    $counts = $tally['counts'];
    $total = (int) ($counts[$view] ?? 0);
    $pageIds = jeab_reminders_sql_page_ids($store, $company, $who, $view, $tag, $offset, $limit, $today, $now);
    $end = $offset + count($pageIds);
    $pageCount = count($pageIds);
    if ($openId > 0 && $offset === 0 && !in_array($openId, $pageIds, true)
        && jeab_reminders_sql_has_id($store, $openId, $company, $who, $view, $tag, $today, $now)) {
        $pageIds[] = $openId;
    }
    $rows = jeab_reminders_sql_rows_for_ids($store, $pageIds);
    if (count($pageIds) > $pageCount && $rows !== []) {
        $rows[count($rows) - 1]['_jeab_open_extra'] = true;
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'offset' => $offset,
        'next' => $end < $total ? $end : null,
        'counts' => $counts,
        'who' => $withWho ? $tally['who'] : [],
    ];
}

/**
 * Billig synk: samma stämpel och siffror som listan, utan kort-HTML.
 *
 * @param list<array<string, mixed>> $rows
 * @param array{today?:int,scheduled?:int,all?:int,waiting?:int,done?:int} $counts
 * @return array{ok:bool,stamp:string,total:int,next:?int,counts:array<string,int>}
 */
function jeab_reminder_pulse_payload(array $rows, array $counts, ?int $total = null, ?int $next = null): array
{
    return [
        'ok' => true,
        'stamp' => jeab_reminder_list_stamp($rows),
        'total' => $total ?? count($rows),
        'next' => $next,
        'counts' => [
            'today' => (int) ($counts['today'] ?? 0),
            'scheduled' => (int) ($counts['scheduled'] ?? 0),
            'all' => (int) ($counts['all'] ?? 0),
            'waiting' => (int) ($counts['waiting'] ?? 0),
            'done' => (int) ($counts['done'] ?? 0),
        ],
    ];
}

/** Siffrorna i flikarna för den vy som redan är öppen. Samma urval som listan. */
function jeab_reminder_view_counts(JeabStore $store): array
{
    $company = jeab_selected_company();
    $who = jeab_reminder_who_filter();
    $all = jeab_list_reminders($store, true, $company, null);
    $scoped = jeab_filter_reminders_scope($all, $company, $who);
    $search = trim((string) ($_GET['q'] ?? ''));
    $tag = $search !== '' ? ['mode' => 'all', 'tag' => null] : jeab_reminder_tag_filter();

    return jeab_reminder_counts($scoped, $tag, $search);
}

function jeab_reminder_due_with_clock(string $label, ?string $dueAt): string
{
    if ($label === '' || $dueAt === null || trim($dueAt) === '' || !jeab_due_has_clock($dueAt)) {
        return $label;
    }
    try {
        $clock = (new DateTimeImmutable($dueAt, new DateTimeZone(date_default_timezone_get())))->format('H:i');
    } catch (Throwable) {
        return $label;
    }
    if (preg_match('/\d{2}:\d{2}$/', $label) === 1) {
        return $label;
    }

    return $label . ' ' . $clock;
}

function jeab_reminder_counts(array $rows, string|array|null $tag = null, string $query = ''): array
{
    $query = trim($query);
    if ($query !== '') {
        // Samma sök- och taggfilter som listan. Klara räknas inte som väntande.
        $rows = jeab_filter_reminders($rows, 'all', $tag, $query);
    } elseif ($tag !== null) {
        $rows = jeab_filter_reminders_by_tag($rows, $tag);
    }
    $today = (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
    $counts = ['today' => 0, 'scheduled' => 0, 'all' => 0, 'waiting' => 0, 'done' => 0];
    $now = jeab_now();
    foreach ($rows as $row) {
        $done = !empty($row['completed_at']);
        $due = $row['due_at'] ?? null;
        if ($done) {
            $counts['done']++;
            $counts['all']++;
            continue;
        }
        $counts['all']++;
        if ($due) {
            $counts['scheduled']++;
        }
        if (jeab_reminder_in_today($row, $today)) {
            $counts['today']++;
        }
        if (jeab_reminder_is_waiting($row, $now)) {
            $counts['waiting']++;
        }
    }
    return $counts;
}

function jeab_reminder_open_id(): int
{
    return max(0, (int) ($_GET['open'] ?? 0));
}

/**
 * Ett öppet kort. Listan och räknarna rörs inte.
 *
 * @return array{ok:bool,html:string,id:int}
 */
function jeab_reminder_card_payload(JeabStore $store): array
{
    $id = jeab_reminder_open_id();
    if (function_exists('jeab_csrf_token')) {
        jeab_csrf_token();
    }
    if (PHP_SAPI !== 'cli' && function_exists('jeab_release_session') && session_status() === PHP_SESSION_ACTIVE) {
        jeab_release_session();
    }
    $GLOBALS['_JEAB_PAGE_REQUEST'] = true;
    if (function_exists('jeab_ordrar_external_live')) {
        jeab_ordrar_external_live(false);
    }
    $row = $id > 0 ? jeab_get_reminder($store, $id) : null;
    if (!is_array($row) || jeab_reminder_is_trashed($row)) {
        return ['ok' => false, 'html' => '', 'id' => $id];
    }
    if (!empty($row['_jeab_list_slim']) || !array_key_exists('note', $row)) {
        $filled = jeab_reminders_fill_bodies($store, [$row]);
        $row = is_array($filled[0] ?? null) ? $filled[0] : $row;
    }
    jeab_reminder_preload_contacts([$row]);
    jeab_reminder_arrived_preload([$row]);
    $html = jeab_reminder_row($row, true);

    return ['ok' => $html !== '', 'html' => $html, 'id' => $id];
}

/** Fliken där en direktlänk (?open=) hör hemma: Idag, Väntar, Klara eller Alla. */
function jeab_reminder_open_tab(array $row): string
{
    if (!empty($row['completed_at'])) {
        return 'done';
    }
    if (jeab_reminder_in_today($row)) {
        return 'today';
    }
    if (jeab_reminder_is_waiting($row)) {
        return 'waiting';
    }
    return 'all';
}

/**
 * ?open= som personen eller fliken gömmer visas ändå.
 * co i adressen vinner över påminnelsens kontor. Utan co byts kontoret så raden syns.
 */
function jeab_reminders_apply_open(JeabStore $store): void
{
    $openId = jeab_reminder_open_id();
    if ($openId <= 0) {
        return;
    }
    $row = jeab_get_reminder($store, $openId);
    if (!is_array($row) || jeab_reminder_is_trashed($row)) {
        return;
    }
    $company = jeab_selected_company();
    $rowCompany = jeab_reminder_company($row);
    $urlCo = jeab_normalize_company($_GET['co'] ?? null);
    // co i adressen vinner över påminnelsens kontor och sessionen.
    if ($urlCo !== null && $urlCo !== $rowCompany) {
        return;
    }
    $who = jeab_reminder_who_filter();
    $search = trim((string) ($_GET['q'] ?? ''));
    $scope = jeab_reminder_list_scope(jeab_reminder_view(), jeab_reminder_tag_filter(), $search);
    $shown = $rowCompany === $company
        && jeab_reminder_matches_who($row, $who)
        && jeab_filter_reminders([$row], (string) $scope['view'], $scope['tag'], $search) !== [];
    if ($shown) {
        return;
    }
    $_GET['co'] = $rowCompany;
    $_GET['who'] = 'all';
    $_GET['ws'] = (string) time();
    $_GET['v'] = jeab_reminder_open_tab($row);
    unset($GLOBALS['_JEAB_WHO_SRC']);
    if (jeab_filter_reminders([$row], (string) $_GET['v'], jeab_reminder_tag_filter(), '') === []) {
        $_GET['tag'] = 'all';
    }
    if ($search !== '' && jeab_filter_reminders([$row], 'all', ['mode' => 'all', 'tag' => null], $search) === []) {
        unset($_GET['q']);
    }
}

function jeab_reminder_company(array $row): string
{
    return jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
}

function jeab_reminder_person_id(array $row): ?string
{
    $raw = trim((string) ($row['person'] ?? ''));
    if ($raw === '') {
        return null;
    }
    $company = jeab_reminder_company($row);
    $key = $company . "\0" . $raw;
    $cache = $GLOBALS['_JEAB_PERSON_ID_CACHE'] ?? null;
    if (is_array($cache) && array_key_exists($key, $cache)) {
        $hit = $cache[$key];

        return is_string($hit) ? $hit : null;
    }
    $person = jeab_person_by_name($raw, $company);
    $id = null;
    if ($person) {
        $id = (string) $person['id'];
    } else {
        foreach (jeab_people() as $one) {
            if (strcasecmp((string) $one['id'], $raw) === 0) {
                $id = (string) $one['id'];
                break;
            }
        }
        if ($id === null) {
            $id = $raw;
        }
    }
    if (!is_array($cache)) {
        $cache = [];
    }
    $cache[$key] = $id;
    $GLOBALS['_JEAB_PERSON_ID_CACHE'] = $cache;

    return $id;
}

function jeab_reminder_who_is_all(string $who): bool
{
    return strcasecmp(trim($who), 'all') === 0;
}

function jeab_reminder_who_all_requested(): bool
{
    $raw = jeab_reminder_who_raw();
    return $raw !== null && jeab_reminder_who_is_all($raw);
}

function jeab_reminder_who_me_name(?string $company = null): ?string
{
    $person = function_exists('jeab_who_me_person') ? jeab_who_me_person() : jeab_auth_user_person();
    $name = trim((string) ($person['name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    return jeab_resolve_person_name($name, $company);
}

/**
 * Mina-personen i Påminnelser. Samma identitet som Egna i Inkorg (jeab_egna_person):
 * säker inloggning, annars sessionens jeab_code_person. Delad/oklar inloggning = okänd.
 * Bara Mina-räknaren, Mina-filtret och chippet. Förvald ansvarig vid nya påminnelser rörs inte.
 *
 * @return array<string, mixed>|null
 */
function jeab_reminder_mina_person(): ?array
{
    if (function_exists('jeab_egna_person')) {
        $person = jeab_egna_person();
    } else {
        $person = function_exists('jeab_who_me_person') ? jeab_who_me_person() : null;
    }

    return is_array($person) && trim((string) ($person['name'] ?? '')) !== '' ? $person : null;
}

/** Mina-personens namn i bolaget, eller null när personen är okänd. */
function jeab_reminder_mina_me_name(?string $company = null): ?string
{
    $person = jeab_reminder_mina_person();
    if (!is_array($person)) {
        return null;
    }
    $company = jeab_normalize_company($company) ?? jeab_selected_company();

    return jeab_resolve_person_name(trim((string) $person['name']), $company);
}

/** Sidor där ?who= betyder Påminnelsers personfilter (inköpssidan bär samma val). */
function jeab_reminder_who_page(): bool
{
    $page = strtolower(trim((string) ($_GET['p'] ?? 'reminders')));
    return in_array($page, ['', 'reminders', 'reminder', 'purchase'], true);
}

/**
 * Öppnad från menyn: p=reminders utan något arbetsläge i adressen.
 * Då gäller förvalet (inloggad person) och ett sparat val glöms.
 */
function jeab_reminder_menu_open(): bool
{
    $page = strtolower(trim((string) ($_GET['p'] ?? 'reminders')));
    if (!in_array($page, ['', 'reminders'], true) || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return false;
    }
    foreach (['who', 'v', 'tag', 'q', 'open', 'n', 'flash', 'intent'] as $key) {
        if (array_key_exists($key, $_GET)) {
            return false;
        }
    }
    return true;
}

/** Hur länge ett sparat personval (t.ex. Alla) gäller utan att användas. Sedan gäller förvalet igen. */
const JEAB_REMINDER_WHO_TTL = 30 * 60;

function jeab_reminder_who_save(string $company, ?string $raw): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if ($raw === null || trim($raw) === '') {
        unset($_SESSION['jeab_reminder_who'][$company]);
        return;
    }
    $_SESSION['jeab_reminder_who'][$company] = ['who' => trim($raw), 'at' => time()];
}

/** Sparat val om det är färskt; äldre format (bara en sträng) och gamla val glöms. */
function jeab_reminder_who_saved(string $company): ?string
{
    $saved = $_SESSION['jeab_reminder_who'][$company] ?? null;
    if (!is_array($saved)) {
        if ($saved !== null) {
            unset($_SESSION['jeab_reminder_who'][$company]);
        }
        return null;
    }
    $who = trim((string) ($saved['who'] ?? ''));
    $at = (int) ($saved['at'] ?? 0);
    if ($who === '' || $at <= 0 || time() - $at > JEAB_REMINDER_WHO_TTL) {
        unset($_SESSION['jeab_reminder_who'][$company]);
        return null;
    }
    $_SESSION['jeab_reminder_who'][$company]['at'] = time();
    return $who;
}

/**
 * Aktivt personval: ?who= först, annars valet som sparats i sessionen medan man arbetar
 * (bläddring, Visa fler, sök, tillbaka från en påminnelse). Menyn nollställer, och ett
 * sparat val glöms efter JEAB_REMINDER_WHO_TTL utan användning.
 * null = inget aktivt val, förvalet (inloggad person) gäller.
 */
function jeab_reminder_who_raw(): ?string
{
    $company = jeab_selected_company();
    $session = session_status() === PHP_SESSION_ACTIVE;
    $onPage = jeab_reminder_who_page();
    if ($onPage && array_key_exists('who', $_GET)) {
        $raw = trim((string) $_GET['who']);
        if ($raw === '') {
            // Tomt who= (bolagsbyte utan val som passar där) = förvalet gäller i det bolaget.
            jeab_reminder_who_save($company, null);
            $GLOBALS['_JEAB_WHO_SRC'] = 'url-empty';
            return null;
        }
        if (function_exists('jeab_who_is_other_sam') && jeab_who_is_other_sam($raw)) {
            jeab_reminder_who_save($company, null);
            $GLOBALS['_JEAB_WHO_SRC'] = 'other-sam';
            return null;
        }
        $saved = $session ? jeab_reminder_who_saved($company) : null;
        $me = jeab_reminder_mina_me_name($company);
        $loginName = function_exists('jeab_who_me_name') ? jeab_who_me_name() : '';
        $minaName = trim((string) (jeab_reminder_mina_person()['name'] ?? ''));
        $isMe = ($me !== null && strcasecmp($raw, $me) === 0)
            || ($loginName !== '' && strcasecmp($raw, $loginName) === 0)
            || ($minaName !== '' && strcasecmp($raw, $minaName) === 0);
        // Sök bär det valda namnet även när ws hunnit bli gammal (fliken har stått öppen).
        // Utan q gäller den gamla adressen inte: förvalet tar över.
        $searching = trim((string) ($_GET['q'] ?? '')) !== '';
        $fresh = (function_exists('jeab_who_url_fresh') && jeab_who_url_fresh()) || $searching;
        if ($isMe || $fresh || ($saved !== null && strcasecmp($saved, $raw) === 0)) {
            jeab_reminder_who_save($company, $raw);
            $GLOBALS['_JEAB_WHO_SRC'] = $isMe ? 'url-me' : ($fresh ? 'url' : 'url+session');
            return $raw;
        }
        // Gammal adress (bokmärke, öppen flik, historik, hemskärm) med t.ex. who=all:
        // gäller inte längre, förvalet tar över.
        jeab_reminder_who_save($company, null);
        $GLOBALS['_JEAB_WHO_SRC'] = 'url-stale-ignored';
        return null;
    }
    if (!$session) {
        $GLOBALS['_JEAB_WHO_SRC'] = 'default';
        return null;
    }
    if (jeab_reminder_menu_open()) {
        jeab_reminder_who_save($company, null);
        $GLOBALS['_JEAB_WHO_SRC'] = 'menu';
        return null;
    }
    $saved = jeab_reminder_who_saved($company);
    if ($saved !== null && function_exists('jeab_who_is_other_sam') && jeab_who_is_other_sam($saved)) {
        jeab_reminder_who_save($company, null);
        $GLOBALS['_JEAB_WHO_SRC'] = 'other-sam';
        return null;
    }
    $GLOBALS['_JEAB_WHO_SRC'] = $saved !== null ? 'session' : 'default';
    return $saved;
}

/**
 * Personval som följer med vid byte Malmö/Järfälla: Alla följer alltid med, en person
 * bara om hen finns i det andra bolaget. Annars null = det bolagets eget val/förval.
 */
function jeab_reminder_who_for_company(string $company): ?string
{
    $raw = jeab_reminder_who_raw();
    if ($raw === null) {
        return null;
    }
    if (jeab_reminder_who_is_all($raw)) {
        return 'all';
    }
    if (function_exists('jeab_who_is_other_sam') && jeab_who_is_other_sam($raw)) {
        return null;
    }
    $from = jeab_resolve_person_name($raw, jeab_selected_company()) ?? $raw;
    if (function_exists('jeab_who_is_other_sam') && jeab_who_is_other_sam($from)) {
        return null;
    }
    $to = jeab_resolve_person_name($from, $company);
    if ($to !== null && function_exists('jeab_who_is_other_sam') && jeab_who_is_other_sam($to)) {
        return null;
    }
    return $to;
}

function jeab_reminder_who_filter_sig(): string
{
    $session = '';
    if (isset($_SESSION['jeab_reminder_who'])) {
        $encoded = json_encode($_SESSION['jeab_reminder_who']);
        $session = is_string($encoded) ? $encoded : '';
    }
    $fp = function_exists('jeab_auth_user_fingerprint') ? (string) jeab_auth_user_fingerprint() : '';
    $email = function_exists('jeab_auth_user_email') ? (string) jeab_auth_user_email() : '';
    $parts = [$fp, $email, $session];
    foreach (['who', 'ws', 'co', 'p', 'v', 'tag', 'q', 'open', 'n', 'flash', 'intent'] as $key) {
        $parts[] = array_key_exists($key, $_GET) ? '1' . (string) $_GET[$key] : '0';
    }

    return implode("\0", $parts);
}

/** Samma personval under en request. Räknas om när adressen eller sessionen ändras. */
function jeab_reminder_who_filter(): ?string
{
    $prior = jeab_reminder_who_filter_sig();
    $memo = $GLOBALS['_JEAB_REMINDER_WHO_MEMO'] ?? null;
    if (is_array($memo) && ($memo['sig'] ?? null) === $prior && array_key_exists('who', $memo)) {
        return $memo['who'];
    }
    $who = jeab_reminder_who_filter_compute();
    $GLOBALS['_JEAB_REMINDER_WHO_MEMO'] = [
        'sig' => jeab_reminder_who_filter_sig(),
        'who' => $who,
    ];

    return $who;
}

function jeab_reminder_who_filter_compute(): ?string
{
    $raw = jeab_reminder_who_raw();
    $src = (string) ($GLOBALS['_JEAB_WHO_SRC'] ?? '');
    if ($raw === null) {
        if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
            jeab_reminder_who_diag(null, $src . '>demo-all');

            return null;
        }
        $who = null;
        if (function_exists('jeab_filter_login_preset')) {
            $presetWho = jeab_filter_login_preset()['who'] ?? null;
            if (is_string($presetWho) && $presetWho !== '') {
                $who = $presetWho;
            }
        }
        // Ingen koppling inloggning -> person ger null, alltså Alla. Ingen fråga.
        $who = $who ?? jeab_reminder_mina_me_name();
        jeab_reminder_who_diag($who, $src . '>default:' . (function_exists('jeab_who_me_source') ? jeab_who_me_source() : '?'));
        return $who;
    }
    $who = jeab_reminder_who_is_all($raw) ? null : jeab_resolve_person_name($raw, jeab_selected_company());
    if ($who === null && $raw !== null && !jeab_reminder_who_is_all($raw) && function_exists('jeab_who_me_name')
        && strcasecmp(jeab_who_me_name(), $raw) === 0) {
        $who = jeab_who_me_name();
    }
    if ($who === null && $raw !== null && !jeab_reminder_who_is_all($raw)) {
        $who = jeab_reminder_mina_me_name(jeab_selected_company());
    }
    $blocked = ($raw !== null && function_exists('jeab_who_is_other_sam') && jeab_who_is_other_sam($raw))
        || ($who !== null && function_exists('jeab_who_is_other_sam') && jeab_who_is_other_sam($who));
    if ($blocked) {
        jeab_reminder_who_save(jeab_selected_company(), null);
        $who = jeab_reminder_mina_me_name();
        $src = 'other-sam';
    }
    jeab_reminder_who_diag($who, $src);
    return $who;
}

function jeab_reminder_who_diag(?string $who, string $src): void
{
    if (function_exists('jeab_who_diag_note') && jeab_reminder_who_page()) {
        jeab_who_diag_note('reminders', $who, $src);
    }
}

/** Personen i bolaget, annars den enda träffen i hela personalen. */
function jeab_reminder_person_record(string $name, ?string $company = null): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    if ($company !== null) {
        $hit = jeab_person_by_name($name, $company);
        if (is_array($hit)) {
            return $hit;
        }
    }
    $hits = [];
    foreach (jeab_people() as $row) {
        if (jeab_person_alias_matches($row, $name) || strcasecmp((string) ($row['id'] ?? ''), $name) === 0) {
            $hits[] = $row;
        }
    }
    return count($hits) === 1 ? $hits[0] : null;
}

function jeab_reminder_matches_who(array $row, ?string $who): bool
{
    if ($who === null || $who === '') {
        return true;
    }
    $company = jeab_reminder_company($row);
    $wantKey = $company . "\0" . $who;
    $wantCache = $GLOBALS['_JEAB_WHO_WANT'] ?? null;
    if (!is_array($wantCache) || !array_key_exists($wantKey, $wantCache)) {
        if (!is_array($wantCache)) {
            $wantCache = [];
        }
        $wantCache[$wantKey] = jeab_reminder_person_record($who, $company);
        $GLOBALS['_JEAB_WHO_WANT'] = $wantCache;
    }
    $wantPerson = $wantCache[$wantKey];
    if (!is_array($wantPerson)) {
        return false;
    }
    $want = (string) $wantPerson['name'];
    $aliases = function_exists('jeab_person_aliases') ? jeab_person_aliases($wantPerson) : [$want, (string) $wantPerson['id']];
    $personId = trim((string) ($row['person'] ?? ''));
    foreach ($aliases as $alias) {
        if ($personId !== '' && strcasecmp($personId, $alias) === 0) {
            return true;
        }
    }
    if (in_array($want, jeab_assignees_from_row($row), true)) {
        return true;
    }
    $parts = [];
    if (is_array($row['assignees'] ?? null)) {
        foreach ($row['assignees'] as $part) {
            if (is_string($part) && trim($part) !== '') {
                $parts[] = trim($part);
            }
        }
    }
    $blob = trim((string) ($row['assignee'] ?? ''));
    if ($blob !== '') {
        foreach (preg_split('/\s*,\s*/', $blob) ?: [] as $part) {
            if (trim((string) $part) !== '') {
                $parts[] = trim((string) $part);
            }
        }
    }
    foreach ($parts as $part) {
        foreach ($aliases as $alias) {
            if (strcasecmp($part, $alias) === 0) {
                return true;
            }
        }
    }

    return false;
}

function jeab_filter_reminders_scope(array $rows, ?string $company = null, ?string $who = null): array
{
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    if ($who !== null) {
        $person = jeab_reminder_person_record($who, $company);
        $who = is_array($person) ? (string) ($person['name'] ?? '') : '';
        if ($who === '') {
            return [];
        }
    } else {
        $who = jeab_reminder_who_filter();
    }
    return array_values(array_filter(
        $rows,
        static function (array $row) use ($company, $who): bool {
            if (jeab_reminder_company($row) !== $company) {
                return false;
            }
            return jeab_reminder_matches_who($row, $who);
        }
    ));
}

/**
 * Personvalen för det valda bolaget. Andra kontorets personal visas inte.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<string>
 */
function jeab_reminder_who_options(string $company, array $rows): array
{
    unset($rows);
    $company = jeab_normalize_company($company) ?? jeab_selected_company();

    return jeab_company_names($company);
}

/**
 * Antal per person med samma vy, tagg, sök och exakta personmatchning som listan.
 * Nyckeln all är hela bolaget. En person kan inte bli fler än all.
 *
 * @param list<array<string, mixed>> $rows
 * @return array<string, int>
 */
function jeab_reminder_who_counts(array $rows, string $company, string $view, string|array|null $tag, string $query): array
{
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    $companyRows = [];
    foreach ($rows as $row) {
        if (is_array($row) && jeab_reminder_company($row) === $company) {
            $companyRows[] = $row;
        }
    }
    $started = microtime(true);
    // Samma predikat som förr (person, sedan vy/tagg/sök). Vyfiltret är oberoende av person,
    // så ett svep räcker och varje person räknas på den redan filtrerade mängden.
    $matched = jeab_filter_reminders($companyRows, $view, $tag, $query);
    $out = ['all' => count($matched)];
    $names = jeab_reminder_who_options($company, $companyRows);
    foreach ($names as $name) {
        $out[$name] = 0;
    }
    if ($names !== []) {
        foreach ($matched as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($names as $name) {
                if (jeab_reminder_matches_who($row, $name)) {
                    $out[$name]++;
                }
            }
        }
    }
    jeab_reminder_list_diag_note('who', microtime(true) - $started, count($matched));

    return $out;
}

/** Sökträffar bland alla personer i valt bolag (används när de egna saknar träff). */
function jeab_reminder_search_fallback(array $rows, string $view, string|array|null $tag, string $search, ?string $company = null): array
{
    if (trim($search) === '') {
        return [];
    }
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    $rows = array_values(array_filter($rows, static fn ($r): bool => is_array($r) && jeab_reminder_company($r) === $company));
    return jeab_filter_reminders($rows, $view, $tag, $search);
}

function jeab_search_reminder_names(JeabStore $store, string $query, ?string $company = null): array
{
    $query = trim($query);
    $len = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
    if ($len < 3) {
        return [];
    }
    $needle = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    $hits = [];
    foreach ($store->listReminders(true) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (jeab_reminder_company($row) !== $company) {
            continue;
        }
        $name = jeab_reminder_shown_customer($row)['name'];
        if ($name === '') {
            continue;
        }
        $hay = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (!str_contains($hay, $needle)) {
            continue;
        }
        $hits[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => $name,
            'email' => jeab_reminder_shown_customer($row)['email'],
            'phone' => jeab_reminder_shown_customer($row)['phone'],
            'address' => jeab_reminder_shown_customer($row)['display'],
            'notes' => jeab_reminder_display_note($row),
        ];
    }
    usort($hits, static function (array $a, array $b): int {
        $cmp = strcasecmp((string) $a['name'], (string) $b['name']);
        return $cmp !== 0 ? $cmp : ((int) $a['id'] <=> (int) $b['id']);
    });
    return $hits;
}

/**
 * Autocomplete for sack pickup: match name or address (≥3 chars).
 *
 * @return list<array{id:int,name:string,phone:string,address:string,street:string,city:string,label:string}>
 */
function jeab_search_reminders_for_sack(JeabStore $store, string $query, ?string $company = null, int $limit = 12): array
{
    $query = trim($query);
    $len = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
    if ($len < 3) {
        return [];
    }
    $needle = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    $limit = max(1, min(30, $limit));
    $hits = [];
    $seen = [];
    foreach ($store->listReminders(true) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (jeab_reminder_company($row) !== $company) {
            continue;
        }
        $view = jeab_reminder_shown_customer($row);
        $name = $view['name'];
        $address = $view['display'] !== '' ? $view['display'] : jeab_reminder_address_source($row);
        if ($name === '' && $address === '') {
            continue;
        }
        $hay = function_exists('mb_strtolower')
            ? mb_strtolower($name . ' ' . $address, 'UTF-8')
            : strtolower($name . ' ' . $address);
        if (!str_contains($hay, $needle)) {
            continue;
        }
        $street = $view['street'] !== '' ? $view['street'] : ($address !== '' ? jeab_reminder_street($address) : '');
        $city = $view['city'];
        $key = function_exists('mb_strtolower')
            ? mb_strtolower($name . '|' . $street . '|' . $city, 'UTF-8')
            : strtolower($name . '|' . $street . '|' . $city);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $labelParts = array_values(array_filter([$name, $street !== '' ? $street : $address, $city], static fn(string $p): bool => $p !== ''));
        $hits[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => $name,
            'phone' => $view['phone'],
            'address' => $address,
            'street' => $street !== '' ? $street : $address,
            'city' => $city,
            'label' => implode(' · ', $labelParts),
        ];
        if (count($hits) >= $limit) {
            break;
        }
    }
    usort($hits, static function (array $a, array $b): int {
        $cmp = strcasecmp((string) $a['name'], (string) $b['name']);
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcasecmp((string) $a['street'], (string) $b['street']);
        return $cmp !== 0 ? $cmp : ((int) $a['id'] <=> (int) $b['id']);
    });
    return $hits;
}

function jeab_sack_mail_to(): string
{
    return 'container@example.test';
}

/**
 * Valfri kommentar till hämtningsmejlet. Tom sträng om den saknas.
 *
 * @param array<string, mixed> $input
 */
function jeab_sack_comment_text(array $input): string
{
    $raw = $input['kommentar'] ?? $input['comment'] ?? '';
    if (!is_string($raw)) {
        $raw = is_scalar($raw) ? (string) $raw : '';
    }
    $raw = trim($raw);
    $raw = str_replace("\0", '', $raw);
    if (function_exists('mb_substr')) {
        return mb_substr($raw, 0, 2000, 'UTF-8');
    }
    return substr($raw, 0, 2000);
}

/**
 * Bygg mejltext och skicka hämtningsbeställning via systemmejlet.
 * Uppladdade filer bifogas inte.
 *
 * @param array<string, mixed> $input
 * @return array{ok:bool,error:?string,body:string,subject:string,to:string}
 */
function jeab_sack_send(array $input): array
{
    $name = trim((string) ($input['namn'] ?? $input['name'] ?? ''));
    $address = trim((string) ($input['adress'] ?? $input['address'] ?? ''));
    $city = trim((string) ($input['ort'] ?? $input['city'] ?? ''));
    $phone = trim((string) ($input['telefon'] ?? $input['phone'] ?? ''));
    if ($name === '' || $address === '' || $city === '' || $phone === '') {
        throw new InvalidArgumentException('Alla fält måste vara ifyllda.');
    }
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) < 8 || strlen($digits) > 15) {
        throw new InvalidArgumentException('Ogiltigt telefonnummer.');
    }

    $comment = jeab_sack_comment_text($input);
    $product = 'All-inclusive XL 1,6 kbm';
    $lines = [
        'BESTÄLLNING HÄMTNING TILL DemoS',
        'Produkt: ' . $product,
        '',
        'Namn: ' . $name,
        'Adress: ' . $address,
        'Ort: ' . $city,
        'Telefon: ' . $phone,
    ];
    if ($comment !== '') {
        $lines[] = '';
        $lines[] = 'Kommentar:';
        $lines[] = $comment;
    }
    $lines[] = '';
    $lines[] = 'Det är endast 1 st välfylld säck, ganska lätt, mestadels kartong och trä.';
    $lines[] = '/ Demo Energi Järfälla, Sam 080000002';
    $lines[] = '';
    $lines[] = 'Skickad: ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $body = implode("\n", $lines);
    $subject = 'Hämtning All-inclusive XL 1,6 kbm – ' . $address . ', ' . $city;
    $to = jeab_sack_mail_to();

    $result = jeab_system_mail_send([
        'to' => $to,
        'subject' => $subject,
        'text' => $body,
        'tags' => ['hamtning', 'sack'],
        'guard_mode' => 'manual',
        'guard_template' => 'hamtning',
        'guard_via' => 'manual',
    ]);
    return [
        'ok' => !empty($result['ok']),
        'error' => isset($result['error']) ? (is_string($result['error']) ? $result['error'] : null) : null,
        'body' => $body,
        'subject' => $subject,
        'to' => $to,
    ];
}

function jeab_reminders_url(array $extra = []): string
{
    $cacheKey = json_encode([
        $extra,
        $GLOBALS['_JEAB_REMINDER_WHO_FOR_URL'] ?? null,
        (string) ($_GET['v'] ?? ''),
        (string) ($_GET['tag'] ?? ''),
        (string) ($_GET['q'] ?? ''),
        (string) ($_GET['co'] ?? ''),
        (string) ($_GET['open'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    if (is_string($cacheKey)) {
        $hit = $GLOBALS['_JEAB_REM_URL'][$cacheKey] ?? null;
        if (is_string($hit)) {
            return $hit;
        }
    }
    $q = [
        'p' => 'reminders',
        'v' => array_key_exists('v', $extra) ? (string) $extra['v'] : jeab_reminder_view(),
    ];
    if ($q['v'] === '') {
        $q['v'] = jeab_reminder_view();
    }
    if (array_key_exists('tag', $extra)) {
        $rawTag = $extra['tag'];
        if (is_array($rawTag)) {
            $q['tag'] = jeab_reminder_tag_query($rawTag);
        } else {
            $rawTag = trim((string) ($rawTag ?? ''));
            if (jeab_reminder_tag_is_all($rawTag)) {
                $q['tag'] = 'all';
            } elseif ($rawTag === '' || jeab_reminder_tag_is_none($rawTag)) {
                $q['tag'] = 'none';
            } else {
                $q['tag'] = jeab_normalize_tag($rawTag) ?? 'none';
            }
        }
    } else {
        $q['tag'] = jeab_reminder_tag_query();
    }
    $search = array_key_exists('q', $extra) ? $extra['q'] : ($_GET['q'] ?? '');
    $search = trim((string) ($search ?? ''));
    if ($search !== '') {
        $q['q'] = $search;
    }
    $open = array_key_exists('open', $extra) ? $extra['open'] : jeab_reminder_open_id();
    $open = (int) ($open ?? 0);
    if ($open > 0) {
        $q['open'] = $open;
    }
    $whoFromExtra = array_key_exists('who', $extra);
    if ($whoFromExtra) {
        $who = $extra['who'];
    } elseif (array_key_exists('_JEAB_REMINDER_WHO_FOR_URL', $GLOBALS)) {
        $who = $GLOBALS['_JEAB_REMINDER_WHO_FOR_URL'];
    } else {
        $who = jeab_reminder_who_filter();
    }
    $company = array_key_exists('co', $extra) ? $extra['co'] : jeab_selected_company();
    unset($extra['v'], $extra['tag'], $extra['q'], $extra['open'], $extra['who'], $extra['co']);
    if (is_string($who) && jeab_reminder_who_is_all($who)) {
        $q['who'] = 'all';
    } elseif (is_string($who) && $who !== '') {
        $q['who'] = $who;
    } elseif (!$whoFromExtra && jeab_reminder_who_all_requested()) {
        $q['who'] = 'all';
    }
    $q['co'] = jeab_normalize_company($company) ?? jeab_selected_company();
    if (isset($q['who'])) {
        // Stämpel: ett val i adressen gäller bara medan man arbetar (se jeab_who_url_fresh).
        $q['ws'] = (string) time();
    }
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $q[$key] = $value;
    }
    $url = jeab_url('index.php?' . http_build_query($q));
    if (is_string($cacheKey)) {
        if (!isset($GLOBALS['_JEAB_REM_URL']) || !is_array($GLOBALS['_JEAB_REM_URL'])) {
            $GLOBALS['_JEAB_REM_URL'] = [];
        }
        $GLOBALS['_JEAB_REM_URL'][$cacheKey] = $url;
    }

    return $url;
}

function jeab_reminder_details_from_input(array $input): array
{
    $company = jeab_normalize_company($input['company'] ?? $input['co'] ?? null) ?? jeab_selected_company();
    $loose = static function (mixed $raw, string $office): array {
        $names = jeab_parse_assignees($raw, null);
        if ($names === []) {
            $names = jeab_parse_assignees($raw, $office);
        }

        return $names;
    };
    $names = [];
    if (array_key_exists('assignees', $input) || array_key_exists('assignee', $input)) {
        $names = $loose(jeab_assignees_from_input($input), $company);
    }
    $who = trim((string) ($input['who'] ?? ''));
    if ($who !== '') {
        foreach ($loose($who, $company) as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
    }
    if (!function_exists('jeab_company_for_assignee_names')) {
        $realign = __DIR__ . '/company_realign.php';
        if (is_file($realign)) {
            require_once $realign;
        }
    }
    if ($names !== [] && function_exists('jeab_company_for_assignee_names')) {
        $follow = jeab_company_for_assignee_names($names);
        if ($follow !== null) {
            $company = $follow;
            $names = jeab_parse_assignees($names, $company);
        }
    }
    $person = $who !== '' ? jeab_person_by_name($who, $company) : null;
    if ($person === null && count($names) === 1) {
        $person = jeab_person_by_name($names[0], $company);
    }
    $customerName = jeab_text_or_null($input['customer'] ?? $input['customer_name'] ?? null);
    $email = jeab_text_or_null($input['email'] ?? null);
    $phone = jeab_normalize_reminder_phone($input['phone'] ?? null);
    $address = jeab_text_or_null($input['address'] ?? null);
    $storeCopy = !function_exists('jeab_contacts_copies_hold')
        || !jeab_contacts_copies_hold()
        || trim((string) ($input['contact_id'] ?? '')) === '';
    return [
        'customer_name' => $storeCopy ? $customerName : null,
        'email' => $storeCopy ? $email : null,
        'phone' => $storeCopy ? $phone : null,
        'address' => $storeCopy ? $address : null,
        'link_customer_name' => $customerName,
        'link_email' => $email,
        'link_phone' => $phone,
        'link_address' => $address,
        'note' => jeab_text_or_null($input['notes'] ?? $input['note'] ?? null),
        'assignees' => $names,
        'company' => $company,
        'person' => $person['id'] ?? null,
        'tags' => jeab_parse_tags($input['tags'] ?? []),
        'flagged' => !empty($input['flagged']),
    ];
}

function jeab_reminder_placeholder_name(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    $fold = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    return $fold === 'namn';
}

function jeab_reminder_input_text(array $input, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $input) || is_array($input[$key])) {
            continue;
        }
        $text = trim((string) $input[$key]);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function jeab_reminder_has_input(array $input, array $keys): bool
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $input)) {
            return true;
        }
    }
    return false;
}

function jeab_reminder_bot_request(array $input): bool
{
    // Formuläret i gränssnittet skickar ui=1. JSON-svaret ska inte göra om det till en bot.
    if ((string) ($input['ui'] ?? '') === '1') {
        return false;
    }
    if (function_exists('jeab_wants_json') && jeab_wants_json()) {
        return true;
    }
    $botKeys = [
        'name', 'namn', 'comment', 'kommentar', 'anteckning', 'bolag', 'due', 'datum',
        'assignee', 'assignees', 'ansvarig', 'telefon', 'tel', 'mobil', 'epost', 'e-post',
        'mail', 'taggar', 'adress',
    ];
    return jeab_reminder_has_input($input, $botKeys);
}

function jeab_reminder_who_text(array $input): string
{
    foreach (['assignee', 'assignees', 'who', 'ansvarig'] as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = $input[$key];
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    continue;
                }
                $text = trim((string) $item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            if ($parts !== []) {
                return implode(', ', $parts);
            }
            continue;
        }
        $text = trim((string) $value);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function jeab_reminder_who_parts(string $who): array
{
    $who = trim($who);
    if ($who === '') {
        return [];
    }
    $decoded = json_decode($who, true);
    if (is_array($decoded)) {
        $bits = [];
        foreach ($decoded as $item) {
            if (is_array($item)) {
                continue;
            }
            $text = trim((string) $item);
            if ($text !== '') {
                $bits[] = $text;
            }
        }
        return $bits;
    }
    $parts = [];
    foreach (preg_split('/\s*,\s*/', $who) ?: [] as $part) {
        $part = trim((string) $part);
        if ($part !== '') {
            $parts[] = $part;
        }
    }
    return $parts;
}

function jeab_reminder_due_from_create(array $input, bool $bot): ?string
{
    $raw = null;
    foreach (['due', 'due_at', 'datum'] as $key) {
        if (!array_key_exists($key, $input) || is_array($input[$key])) {
            continue;
        }
        $text = trim((string) $input[$key]);
        if ($text === '') {
            continue;
        }
        $raw = $text;
        break;
    }
    if ($raw !== null) {
        $parsed = jeab_parse_reminder_due($raw);
        if ($parsed === null) {
            if ($bot) {
                throw new InvalidArgumentException('Ogiltigt datum.');
            }
            return null;
        }
        return $parsed;
    }
    if ($bot) {
        return jeab_tomorrow_start();
    }
    return null;
}

/** Produktkod → samma rubrik som leadflödet. */
function jeab_reminder_product_labels(): array
{
    return [
        'laddbox' => 'Elbilsladdare',
        'batteri' => 'Batteri',
        'sol' => 'Solceller med batteri',
        'sol+batteri' => 'Sol+batteri',
        'okand' => 'okänd produkt',
    ];
}

function jeab_reminder_product_code(string $raw): string
{
    $fold = function_exists('mb_strtolower') ? mb_strtolower(trim($raw), 'UTF-8') : strtolower(trim($raw));
    $fold = strtr($fold, ['ä' => 'a', 'ö' => 'o', 'å' => 'a']);
    $map = [
        'laddbox' => 'laddbox',
        'elbilsladdare' => 'laddbox',
        'elbil' => 'laddbox',
        'batteri' => 'batteri',
        'sol' => 'sol',
        'solceller' => 'sol',
        'solceller med batteri' => 'sol',
        'sol+batteri' => 'sol+batteri',
        'okand' => 'okand',
        'okand produkt' => 'okand',
    ];

    return $map[$fold] ?? 'okand';
}

function jeab_reminder_product_label(string $code): string
{
    $labels = jeab_reminder_product_labels();

    return $labels[$code] ?? $labels['okand'];
}

/**
 * Kundfält från kontakten. Trådens egna kopior används inte.
 *
 * @param array<string, mixed>|null $contact
 * @return array{contact_id:string,name:string,address:string,phone:string,email:string,postal:string,city:string}
 */
function jeab_reminder_contact_snapshot(?array $contact): array
{
    $empty = ['contact_id' => '', 'name' => '', 'address' => '', 'phone' => '', 'email' => '', 'postal' => '', 'city' => ''];
    if (!is_array($contact)) {
        return $empty;
    }
    if (function_exists('jeab_contacts_sole_view_from_contact')) {
        $view = jeab_contacts_sole_view_from_contact($contact);
        $street = trim((string) ($view['street'] ?? ''));
        $display = trim((string) ($view['display'] ?? ''));

        return [
            'contact_id' => trim((string) ($view['id'] ?? $contact['id'] ?? '')),
            'name' => trim((string) ($view['name'] ?? '')),
            'address' => $street !== '' ? $street : $display,
            'phone' => trim((string) ($view['phone'] ?? '')),
            'email' => trim((string) ($view['email'] ?? '')),
            'postal' => trim((string) ($view['postal'] ?? '')),
            'city' => trim((string) ($view['city'] ?? '')),
        ];
    }
    $street = trim((string) ($contact['street'] ?? ''));
    $postal = function_exists('jeab_kontakt_postal')
        ? jeab_kontakt_postal((string) ($contact['postal_code'] ?? ''))
        : trim((string) ($contact['postal_code'] ?? ''));
    $city = function_exists('jeab_address_town')
        ? jeab_address_town((string) ($contact['city'] ?? ''))
        : trim((string) ($contact['city'] ?? ''));

    return [
        'contact_id' => trim((string) ($contact['id'] ?? '')),
        'name' => function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($contact) : '',
        'address' => $street,
        'phone' => function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($contact) : '',
        'email' => function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($contact) : '',
        'postal' => $postal,
        'city' => $city,
    ];
}

/** Påminnelse som redan pekar på ärendet, via thread_id eller mail-länken. */
function jeab_reminder_case_existing(string $tid): ?array
{
    $tid = trim($tid);
    if ($tid === '') {
        return null;
    }
    $row = jeab_reminder_for_thread($tid);
    if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
        return $row;
    }
    if (!function_exists('jeab_lead_find_reminder')) {
        return null;
    }
    $found = jeab_lead_find_reminder($tid, '');
    if (!is_array($found) || (int) ($found['id'] ?? 0) < 1) {
        return null;
    }
    if (function_exists('jeab_inkorg_reminder_links_thread') && !jeab_inkorg_reminder_links_thread($found, $tid)) {
        return null;
    }

    return $found;
}

/**
 * Ett ärende med sina mejl, utan att läsa hela inkorgen.
 *
 * @return array<string, mixed>
 */
function jeab_reminder_case_bundle(string $tid): array
{
    $tid = trim($tid);
    $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
    if (!is_array($meta)) {
        $meta = [];
    }
    $meta['id'] = $tid;
    $msgs = [];
    $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    if (is_object($store) && method_exists($store, 'findMessagesByThread')) {
        try {
            $loaded = $store->findMessagesByThread($tid);
            $msgs = is_array($loaded) ? $loaded : [];
        } catch (Throwable) {
            $msgs = [];
        }
    }
    if ($msgs !== [] && function_exists('jeab_inkorg_thread_row')) {
        return jeab_inkorg_thread_row($tid, $msgs, $meta);
    }

    return $meta;
}

function jeab_reminder_case_offer(array $thread): string
{
    $msg = function_exists('jeab_auto_quote_lead_message')
        ? jeab_auto_quote_lead_message($thread)
        : (array) ($thread['first'] ?? []);
    $labels = is_array($msg['labels'] ?? null) ? $msg['labels'] : [];
    $tags = [];
    foreach ((array) ($thread['tags'] ?? []) as $tag) {
        $tags[] = (string) $tag;
    }
    if (function_exists('jeab_lead_offer')) {
        $offer = jeab_lead_offer($labels, $tags);
        if (in_array($offer, ['laddbox', 'batteri', 'sol', 'sol+batteri'], true)) {
            return $offer;
        }
    }

    return '';
}

/** Ansvarig på ärendet, bara om personen hör till kontoret. */
function jeab_reminder_case_assignee(array $thread, string $company): string
{
    $company = jeab_normalize_company($company) ?? '';
    $id = trim((string) ($thread['assignee'] ?? ''));
    if ($id === '' || $company === '') {
        return '';
    }
    $staff = function_exists('jeab_inkorg_staff') ? jeab_inkorg_staff() : [];
    $name = trim((string) ($staff[$id] ?? $id));
    if ($name === '') {
        return '';
    }
    $resolved = jeab_resolve_person_name($name, $company);

    return $resolved ?? '';
}

/**
 * Kontakten för ett ärende: samma kontakt som Inkorg visar (contact_id på ärendet,
 * inkorgskontaktens länk, e-post), annars en befintlig kontakt med samma e-post eller telefon.
 * Bara läsning.
 *
 * @param array<string, mixed> $thread
 * @return array<string, mixed>|null
 */
function jeab_reminder_case_contact(array $thread): ?array
{
    $contact = jeab_reminder_contact_from_thread_meta($thread);
    if (is_array($contact)) {
        return $contact;
    }
    if (!function_exists('jeab_kontakt_get')) {
        return null;
    }
    if (function_exists('jeab_contacts_sole_inbox_view')) {
        try {
            $view = jeab_contacts_sole_inbox_view($thread);
        } catch (Throwable) {
            $view = [];
        }
        $id = trim((string) ($view['id'] ?? ''));
        $found = $id !== '' ? jeab_kontakt_get($id) : null;
        if (is_array($found)) {
            return $found;
        }
    }
    $cust = jeab_reminder_case_thread_customer($thread);
    if (($cust['email'] !== '' || $cust['phone'] !== '') && function_exists('jeab_inkorg_party_contact_ids')) {
        $ids = jeab_inkorg_party_contact_ids(['email' => $cust['email'], 'phone' => $cust['phone']]);
        if (is_array($ids) && count($ids) === 1) {
            $found = jeab_kontakt_get((string) $ids[0]);
            if (is_array($found)) {
                return $found;
            }
        }
    }

    return null;
}

/**
 * Kunduppgifterna som ärendet själv bär (formulär, avsändare, lead).
 * Används bara när ärendet ännu saknar kontakt i Kontakter.
 *
 * @param array<string, mixed> $thread
 * @return array{name:string,email:string,phone:string,address:string,postal:string,city:string}
 */
function jeab_reminder_case_thread_customer(array $thread): array
{
    $lead = is_array($thread['lead_customer'] ?? null) ? $thread['lead_customer'] : [];
    $party = [];
    if (function_exists('jeab_inkorg_party')) {
        try {
            $party = jeab_inkorg_party($thread);
        } catch (Throwable) {
            $party = [];
        }
    }
    $pick = static function (array $values): string {
        foreach ($values as $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    };
    $email = '';
    foreach ([$party['email'] ?? '', $thread['customer_email'] ?? '', $lead['email'] ?? ''] as $cand) {
        $cand = function_exists('jeab_norm_email') ? jeab_norm_email((string) $cand) : mb_strtolower(trim((string) $cand));
        if ($cand === '' || (function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($cand))) {
            continue;
        }
        $email = $cand;
        break;
    }
    $name = $pick([$party['name'] ?? '', $thread['customer_name'] ?? '', $lead['name'] ?? '']);
    if ($name !== '' && $email !== '' && mb_strtolower($name) === $email) {
        $name = '';
    }
    $phone = $pick([$party['phone'] ?? '', $lead['phone'] ?? '', $thread['customer_phone'] ?? '']);
    $rawAddress = $pick([$party['address'] ?? '', $lead['address'] ?? '', $lead['street'] ?? '']);
    $street = $rawAddress;
    $postal = $pick([$lead['postal_code'] ?? '', $lead['postal'] ?? '', $lead['zip'] ?? '']);
    $city = $pick([$lead['city'] ?? '', $lead['ort'] ?? '']);
    if ($rawAddress !== '' && function_exists('jeab_kontakt_parse_address')) {
        $parsed = jeab_kontakt_parse_address($rawAddress);
        $street = trim((string) ($parsed['street'] ?? '')) !== '' ? trim((string) $parsed['street']) : $rawAddress;
        if ($postal === '') {
            $postal = trim((string) ($parsed['postal'] ?? ''));
        }
        if ($city === '') {
            $city = trim((string) ($parsed['city'] ?? ''));
        }
    }
    if ($postal !== '' && function_exists('jeab_kontakt_postal')) {
        $postal = jeab_kontakt_postal($postal);
    }

    return [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $street,
        'postal' => $postal,
        'city' => $city,
    ];
}

/** Anteckning för en ny påminnelse från ärendet: ärendets rubrik. */
function jeab_reminder_case_note(array $thread): string
{
    $first = is_array($thread['first'] ?? null) ? $thread['first'] : [];
    foreach ([$thread['subject'] ?? '', $first['subject'] ?? ''] as $subject) {
        $subject = trim(preg_replace('/\s+/u', ' ', (string) $subject) ?? '');
        if ($subject !== '') {
            return 'Ärende: ' . mb_substr($subject, 0, 200);
        }
    }

    return '';
}

/** Ärendet har en egen avsändare: minst ett mejl eller ett lead med kunduppgifter. */
function jeab_reminder_case_has_sender(array $thread): bool
{
    $msgs = $thread['messages'] ?? null;
    if (is_array($msgs) && $msgs !== []) {
        return true;
    }
    $first = $thread['first'] ?? null;
    if (is_array($first) && trim((string) ($first['from'] ?? '')) !== '') {
        return true;
    }
    $lead = is_array($thread['lead_customer'] ?? null) ? $thread['lead_customer'] : [];
    foreach (['email', 'phone', 'name'] as $key) {
        if (trim((string) ($lead[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Kontakten hör till ärendets avsändare bara om e-post eller telefon stämmer.
 * Adressen ensam räcker inte: en annan person på samma adress är inte avsändaren.
 * Saknar ärendet både e-post och telefon måste namnet stämma.
 * Utan avsändare (inga mejl, inget lead) är trådens kundfält bara en kopia: kontakten gäller.
 *
 * @param array<string, mixed> $contact
 * @param array{name:string,email:string,phone:string,address:string,postal:string,city:string} $cust
 */
function jeab_reminder_case_contact_matches(array $contact, array $cust, bool $hasSender = true): bool
{
    if (!$hasSender) {
        return true;
    }
    $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($cust['email']) : mb_strtolower(trim($cust['email']));
    $phone = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone($cust['phone']) : trim($cust['phone']);
    if ($email !== '') {
        $emails = function_exists('jeab_kontakt_row_emails') ? jeab_kontakt_row_emails($contact) : [];
        $one = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($contact) : '';
        if ($one !== '') {
            $emails[] = $one;
        }
        if (in_array($email, $emails, true)) {
            return true;
        }
    }
    if ($phone !== '' && function_exists('jeab_kontakt_row_phone') && jeab_kontakt_row_phone($contact) === $phone) {
        return true;
    }
    if ($email !== '' || $phone !== '') {
        return false;
    }
    $name = trim($cust['name']);
    $rowName = function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($contact) : '';
    if ($name === '' || $rowName === '') {
        return true;
    }

    return function_exists('jeab_kontakt_party_names_agree') ? jeab_kontakt_party_names_agree($name, $rowName) : true;
}

/**
 * Kundfälten för ett ärende. Namn, e-post och telefon kommer i första hand från avsändaren
 * (från-namn, e-post, telefon ur signaturen). Kontakten används bara när den matchar på
 * e-post eller telefon; den fyller tomma fält och står för adressen.
 *
 * @param array<string, mixed> $thread
 * @param array<string, mixed>|null $contact
 * @param bool $checked Kontakten är redan prövad (eller nyss skapad från ärendet).
 * @return array{contact_id:string,name:string,address:string,phone:string,email:string,postal:string,city:string}
 */
function jeab_reminder_case_fields(array $thread, ?array $contact, bool $checked = false): array
{
    $snap = jeab_reminder_contact_snapshot($contact);
    if (!jeab_reminder_case_has_sender($thread) && $snap['contact_id'] !== '') {
        return $snap;
    }
    $cust = jeab_reminder_case_thread_customer($thread);
    if (!$checked && is_array($contact) && !jeab_reminder_case_contact_matches($contact, $cust)) {
        $snap = jeab_reminder_contact_snapshot(null);
    }
    $first = static fn(string $a, string $b): string => $a !== '' ? $a : $b;
    $useContactPlace = $snap['contact_id'] !== '' && $snap['address'] !== '';

    return [
        'contact_id' => $snap['contact_id'],
        'name' => $first($cust['name'], $snap['name']),
        'address' => $useContactPlace ? $snap['address'] : $first($cust['address'], $snap['address']),
        'phone' => $first($cust['phone'], $snap['phone']),
        'email' => $first($cust['email'], $snap['email']),
        'postal' => $useContactPlace ? $first($snap['postal'], $cust['postal']) : $first($cust['postal'], $snap['postal']),
        'city' => $useContactPlace ? $first($snap['city'], $cust['city']) : $first($cust['city'], $snap['city']),
    ];
}

/**
 * Ärendet saknar kontakt: skapa eller hitta den i Kontakter och koppla den till ärendet,
 * samma väg som leadflödet (jeab_kontakt_find_or_create + contact_id på ärendet).
 * Bara för ett sparat ärende med egna kunduppgifter. Ifyllda formulärfält går före ärendets.
 *
 * @param array<string, mixed> $thread
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
/**
 * Samma kundhint som jeab_reminder_case_link_contact. Null när ärendet inte kan kopplas.
 *
 * @param array<string, mixed> $thread
 * @param array<string, mixed> $input
 * @return array<string, string>|null
 */
function jeab_reminder_case_link_hint(string $tid, array $thread, array $input): ?array
{
    $tid = trim($tid);
    if ($tid === '' || !function_exists('jeab_kontakt_find_or_create')) {
        return null;
    }
    if (!function_exists('jeab_inkorg_thread_stored') || !jeab_inkorg_thread_stored($tid)) {
        return null;
    }
    $cust = jeab_reminder_case_thread_customer($thread);
    if ($cust['name'] === '' && $cust['email'] === '' && $cust['phone'] === '') {
        return null;
    }
    $posted = static fn(string $key): string => trim((string) ($input[$key] ?? ''));

    return [
        'name' => $posted('customer') !== '' ? $posted('customer') : $cust['name'],
        'email' => $posted('email') !== '' ? $posted('email') : $cust['email'],
        'phone' => $posted('phone') !== '' ? $posted('phone') : $cust['phone'],
        'street' => $posted('title') !== '' ? $posted('title') : ($posted('address') !== '' ? $posted('address') : $cust['address']),
        'postal_code' => $posted('postal') !== '' ? $posted('postal') : $cust['postal'],
        'city' => $posted('ort') !== '' ? $posted('ort') : $cust['city'],
        'thread_id' => $tid,
        'source' => 'inkorg',
    ];
}

function jeab_reminder_case_link_contact(string $tid, array $thread, array $input): ?array
{
    $tid = trim($tid);
    $hint = jeab_reminder_case_link_hint($tid, $thread, $input);
    if ($hint === null) {
        return null;
    }
    $contact = jeab_kontakt_find_or_create($hint, true, false, true);
    $cid = is_array($contact) ? trim((string) ($contact['id'] ?? '')) : '';
    if ($cid === '') {
        return null;
    }
    if (function_exists('jeab_inkorg_thread_write')) {
        jeab_inkorg_thread_write($tid, static function (array $t) use ($cid): array {
            if (trim((string) ($t['contact_id'] ?? '')) === '') {
                $t['contact_id'] = $cid;
            }

            return $t;
        });
    }

    return $contact;
}

/** dry saknas eller är 1. dry=0 skriver. */
function jeab_inkorg_link_contact_is_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $d = $input['dry'];
    if ($d === false || $d === 0 || $d === 0.0 || $d === '0') {
        return false;
    }
    if (is_string($d) && in_array(strtolower(trim($d)), ['false', 'no', 'nej', 'off'], true)) {
        return false;
    }

    return true;
}

/**
 * Vad jeab_reminder_case_link_contact skulle göra, utan att skriva.
 *
 * @param array<string, mixed> $thread
 * @param array<string, mixed> $input
 * @return array{result:string,contact_id:string,would_link:int,would_create:int}
 */
function jeab_inkorg_link_contact_plan(string $tid, array $thread, array $input): array
{
    $blank = ['result' => 'skip', 'contact_id' => '', 'would_link' => 0, 'would_create' => 0];
    $hint = jeab_reminder_case_link_hint($tid, $thread, $input);
    if ($hint === null || !function_exists('jeab_kontakter_load') || !function_exists('jeab_kontakt_find')) {
        return $blank;
    }
    $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email((string) $hint['email']) : mb_strtolower(trim((string) $hint['email']));
    $phone = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone((string) $hint['phone']) : trim((string) $hint['phone']);
    $street = (string) $hint['street'];
    if (function_exists('jeab_kontakt_address_placeholder') && jeab_kontakt_address_placeholder($street)) {
        $street = '';
    }
    $streetKey = function_exists('jeab_kontakt_street_key') ? jeab_kontakt_street_key($street) : '';
    $postal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal((string) $hint['postal_code']) : trim((string) $hint['postal_code']);
    $name = trim((string) $hint['name']);
    $found = jeab_kontakt_find(jeab_kontakter_load(), $streetKey, $postal, $email, $phone, true, $name, (string) $hint['city']);
    if (is_string($found) && $found !== '') {
        return ['result' => 'link', 'contact_id' => $found, 'would_link' => 1, 'would_create' => 0];
    }
    if (($name === '' && $email === '') || ($streetKey === '' && $email === '' && $phone === '')) {
        return $blank;
    }

    return ['result' => 'create', 'contact_id' => '', 'would_link' => 0, 'would_create' => 1];
}

/** @return array<string, true> */
function jeab_inkorg_link_contact_ids(): array
{
    if (!function_exists('jeab_kontakter_load')) {
        return [];
    }
    $ids = [];
    foreach (jeab_kontakter_load() as $id => $row) {
        $cid = is_array($row) ? trim((string) ($row['id'] ?? $id)) : trim((string) $id);
        if ($cid !== '') {
            $ids[$cid] = true;
        }
    }

    return $ids;
}

function jeab_inkorg_link_contact_undo_dir(): string
{
    $root = function_exists('jeab_data_dir') ? jeab_data_dir() : '';
    if ($root === '') {
        return '';
    }

    return rtrim(str_replace('\\', '/', $root), '/') . '/.private/inkorg-link-contact';
}

function jeab_inkorg_link_contact_undo_save(string $tid, string $contactId, bool $created): string
{
    $dir = jeab_inkorg_link_contact_undo_dir();
    if ($dir === '' || $tid === '' || $contactId === '') {
        return '';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return '';
    }
    @chmod($dir, 0700);
    $undoId = 'lc_' . bin2hex(random_bytes(8));
    $path = $dir . '/' . $undoId . '.json';
    $payload = [
        'undo_id' => $undoId,
        'thread_id' => $tid,
        'contact_id' => $contactId,
        'created' => $created ? 1 : 0,
        'at' => date('c'),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return '';
    }
    $tmp = $dir . '/.' . $undoId . '.tmp';
    if (file_put_contents($tmp, $json . "\n") === false) {
        @unlink($tmp);

        return '';
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return '';
    }
    @chmod($path, 0600);

    return $undoId;
}

/**
 * inbox.link_contact. Ett ärende. dry=1 är standard och skriver inget.
 * dry=0 anropar jeab_reminder_case_link_contact och sparar undo_id.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_link_contact(array $input): array
{
    $dry = jeab_inkorg_link_contact_is_dry($input);
    $tid = trim((string) ($input['thread_id'] ?? $input['thread'] ?? ''));
    $base = [
        'ok' => false,
        'action' => 'inbox.link_contact',
        'dry' => $dry ? 1 : 0,
        'sent' => 0,
        'would_send' => false,
        'thread_id' => $tid,
        'contact_id' => '',
        'linked' => 0,
        'created' => 0,
        'would_link' => 0,
        'would_create' => 0,
        'result' => '',
        'undo_id' => '',
    ];
    if ($tid === '') {
        return array_merge($base, ['error' => 'ärende saknas']);
    }
    $thread = function_exists('jeab_auto_thread_by_id') ? jeab_auto_thread_by_id($tid) : null;
    if (!is_array($thread) || !function_exists('jeab_inkorg_thread_stored') || !jeab_inkorg_thread_stored($tid)) {
        return array_merge($base, ['error' => 'ärende saknas']);
    }
    $existing = trim((string) ($thread['contact_id'] ?? ''));
    if ($existing === '' && function_exists('jeab_inkorg_thread_meta')) {
        $existing = trim((string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? ''));
    }
    if ($existing !== '') {
        return array_merge($base, [
            'ok' => true,
            'contact_id' => $existing,
            'result' => 'already',
        ]);
    }
    if ($dry) {
        $plan = jeab_inkorg_link_contact_plan($tid, $thread, $input);

        return array_merge($base, $plan, ['ok' => $plan['result'] !== 'skip']);
    }
    $before = jeab_inkorg_link_contact_ids();
    $contact = jeab_reminder_case_link_contact($tid, $thread, $input);
    $cid = is_array($contact) ? trim((string) ($contact['id'] ?? '')) : '';
    if ($cid === '') {
        return array_merge($base, ['error' => 'ingen kontakt', 'result' => 'skip']);
    }
    $created = isset($before[$cid]) ? 0 : 1;
    $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
    $linked = trim((string) ($meta['contact_id'] ?? '')) === $cid ? 1 : 0;
    $undoId = jeab_inkorg_link_contact_undo_save($tid, $cid, $created === 1);

    return array_merge($base, [
        'ok' => true,
        'contact_id' => $cid,
        'linked' => $linked,
        'created' => $created,
        'result' => $created === 1 ? 'create' : 'link',
        'undo_id' => $undoId,
    ]);
}

/**
 * Innan automatiken stänger ett ärende efter utskick. CONTACTS_STRICT kräver
 * contact_id när en påminnelse pekar på raden.
 */
function jeab_auto_link_contact_before_close(string $tid): void
{
    $tid = trim($tid);
    if ($tid === '' || !function_exists('jeab_reminder_case_link_contact') || !function_exists('jeab_inkorg_thread_stored') || !jeab_inkorg_thread_stored($tid)) {
        return;
    }
    $have = function_exists('jeab_inkorg_thread_meta') ? trim((string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? '')) : '';
    if ($have !== '') {
        return;
    }
    $thread = function_exists('jeab_auto_thread_by_id') ? jeab_auto_thread_by_id($tid) : null;
    if (!is_array($thread)) {
        return;
    }
    try {
        jeab_reminder_case_link_contact($tid, $thread, []);
    } catch (Throwable) {
    }
}

/**
 * Stängning efter offert får inte falla på CONTACTS_STRICT. Kontakten länkas först.
 *
 * @template T
 * @param callable(): T $write
 * @return T
 */
function jeab_offer_close_unlock(callable $write): mixed
{
    $had = array_key_exists('_JEAB_OFFER_CLOSE', $GLOBALS);
    $prev = $GLOBALS['_JEAB_OFFER_CLOSE'] ?? null;
    $GLOBALS['_JEAB_OFFER_CLOSE'] = 1;
    try {
        return $write();
    } finally {
        if ($had) {
            $GLOBALS['_JEAB_OFFER_CLOSE'] = $prev;
        } else {
            unset($GLOBALS['_JEAB_OFFER_CLOSE']);
        }
    }
}

/**
 * Förifyllning från ärendet. Namn, e-post och telefon kommer från avsändaren; kontakten
 * (bara vid träff på e-post eller telefon) fyller tomma fält och adressen. Saknas kontakt
 * skapas och kopplas den när påminnelsen sparas.
 *
 * @return array<string, mixed>
 */
function jeab_reminder_case_prefill(string $tid): array
{
    $thread = jeab_reminder_case_bundle($tid);
    $snap = jeab_reminder_case_fields($thread, jeab_reminder_case_contact($thread));
    $company = jeab_normalize_company($thread['company'] ?? null) ?? '';
    if ($company === '' && function_exists('jeab_inkorg_thread_company')) {
        $company = jeab_normalize_company(jeab_inkorg_thread_company($thread)) ?? '';
    }
    if ($company === '') {
        $company = jeab_selected_company();
    }
    $assignee = jeab_reminder_case_assignee($thread, $company);
    if ($assignee === '') {
        $me = jeab_reminder_who_me_name($company);
        $assignee = $me ?? '';
    }
    $existing = jeab_reminder_case_existing($tid);

    return $snap + [
        'thread_id' => trim($tid),
        'company' => $company,
        'product' => jeab_reminder_case_offer($thread),
        'assignee' => $assignee,
        'note' => jeab_reminder_case_note($thread),
        'reminder_id' => is_array($existing) ? (int) ($existing['id'] ?? 0) : 0,
    ];
}

/** @return array{href:string,label:string,id:int} */
function jeab_inkorg_case_reminder_link(string $tid, string $company = ''): array
{
    $tid = trim($tid);
    $existing = $tid !== '' ? jeab_reminder_case_existing($tid) : null;
    if (is_array($existing) && (int) ($existing['id'] ?? 0) > 0) {
        return [
            'href' => jeab_reminders_url(jeab_reminder_saved_query($existing)),
            'label' => 'Påminnelse',
            'id' => (int) $existing['id'],
        ];
    }
    $company = jeab_normalize_company($company) ?? '';
    $q = ['p' => 'reminders', 'ny' => '1', 'thread' => $tid];
    if ($company !== '') {
        $q['co'] = $company;
    }

    return [
        'href' => jeab_url('index.php?' . http_build_query($q)),
        'label' => 'Skapa påminnelse',
        'id' => 0,
    ];
}

function jeab_inkorg_case_reminder_anchor(string $tid, string $company = '', string $class = ''): string
{
    $link = jeab_inkorg_case_reminder_link($tid, $company);
    $class = $class === 'ix-chip' ? 'ix-chip' : '';
    $classAttr = $class !== '' ? ' class="' . $class . '"' : '';

    return '<a' . $classAttr . ' href="' . e($link['href']) . '">' . e($link['label']) . '</a>';
}

/** Om ny=1 redan har en påminnelse: adressen som öppnar den. */
function jeab_reminder_ny_existing_url(): string
{
    if ((string) ($_GET['ny'] ?? '') !== '1') {
        return '';
    }
    $tid = trim((string) ($_GET['thread'] ?? ''));
    if ($tid === '') {
        return '';
    }
    $row = jeab_reminder_case_existing($tid);
    if (!is_array($row) || (int) ($row['id'] ?? 0) < 1) {
        return '';
    }

    return jeab_reminders_url(jeab_reminder_saved_query($row));
}

/**
 * @param array<string, mixed> $row
 */
function jeab_reminder_case_reuse(JeabStore $store, array $row, string $tid): array
{
    $id = (int) ($row['id'] ?? 0);
    $tid = trim($tid);
    if ($id > 0 && $tid !== '' && trim((string) ($row['thread_id'] ?? '')) === '') {
        try {
            $saved = $store->saveReminder($id, ['thread_id' => $tid]);
            if (is_array($saved)) {
                $row = $saved;
            }
        } catch (Throwable) {
        }
    }
    if ($id > 0 && trim((string) ($row['contact_id'] ?? '')) === '') {
        $bundle = jeab_reminder_case_bundle($tid);
        $contact = jeab_reminder_contact_from_thread_meta($bundle);
        if (is_array($contact) && !jeab_reminder_case_contact_matches($contact, jeab_reminder_case_thread_customer($bundle), jeab_reminder_case_has_sender($bundle))) {
            $contact = null;
        }
        $cid = is_array($contact) ? trim((string) ($contact['id'] ?? '')) : '';
        if ($cid !== '' && function_exists('jeab_kontakt_stamp_reminder')) {
            jeab_kontakt_stamp_reminder($store, $id, $cid);
            $fresh = $store->getReminder($id);
            if (is_array($fresh)) {
                $row = $fresh;
            }
        }
    }
    $row['reused'] = true;

    return $row;
}

/**
 * Ärende och kontakt fyller kundfälten från Kontakter och rubriken som leadflödet.
 * Finns påminnelsen redan lämnas den tillbaka. Ingen offert och inget mejl.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminder_create_prepare(JeabStore $store, array $input): array
{
    $threadId = trim((string) ($input['thread_id'] ?? ''));
    $fromContact = (string) ($input['from_contact'] ?? '') === '1';
    $fromCase = (string) ($input['from_case'] ?? '') === '1';
    if ($fromCase && $threadId === '') {
        throw new InvalidArgumentException('Ärendet saknas.');
    }
    if ($threadId !== '') {
        $existing = jeab_reminder_case_existing($threadId);
        if (is_array($existing)) {
            $input['_existing'] = jeab_reminder_case_reuse($store, $existing, $threadId);

            return $input;
        }
    }
    if (!$fromContact && !$fromCase) {
        return $input;
    }
    $contact = null;
    $company = '';
    if ($fromContact) {
        $cid = trim((string) ($input['contact_id'] ?? ''));
        $contact = ($cid !== '' && function_exists('jeab_kontakt_get')) ? jeab_kontakt_get($cid) : null;
        if (!is_array($contact)) {
            throw new InvalidArgumentException('Kontakten saknas.');
        }
        $company = jeab_normalize_company($contact['company'] ?? null) ?? '';
    } else {
        $bundle = jeab_reminder_case_bundle($threadId);
        $contact = jeab_reminder_case_contact($bundle);
        if (is_array($contact) && !jeab_reminder_case_contact_matches($contact, jeab_reminder_case_thread_customer($bundle), jeab_reminder_case_has_sender($bundle))) {
            $contact = null;
        }
        if (!is_array($contact)) {
            $contact = jeab_reminder_case_link_contact($threadId, $bundle, $input);
        }
        if (!is_array($contact)) {
            throw new InvalidArgumentException('Kontakten saknas.');
        }
        $company = jeab_normalize_company($bundle['company'] ?? null) ?? '';
        if ($company === '' && function_exists('jeab_inkorg_thread_company')) {
            $company = jeab_normalize_company(jeab_inkorg_thread_company($bundle)) ?? '';
        }
    }
    if ($company === '') {
        $company = jeab_normalize_company($input['company'] ?? null) ?? jeab_selected_company();
    }
    $input['company'] = $company;
    if (jeab_reminder_who_text($input) === '') {
        $who = '';
        if ($fromCase) {
            $who = jeab_reminder_case_assignee(jeab_reminder_case_bundle($threadId), $company);
        }
        if ($who === '') {
            $me = jeab_reminder_who_me_name($company);
            $who = $me ?? '';
        }
        if ($who !== '') {
            $input['who'] = $who;
        }
    }
    $snap = $fromCase && isset($bundle)
        ? jeab_reminder_case_fields($bundle, $contact, true)
        : jeab_reminder_contact_snapshot($contact);
    if ($snap['contact_id'] === '') {
        throw new InvalidArgumentException('Kontakten saknas.');
    }
    $person = $snap['name'] !== '' ? $snap['name'] : ($snap['email'] !== '' ? $snap['email'] : $snap['address']);
    if ($person === '') {
        throw new InvalidArgumentException('Skriv ett namn.');
    }
    $code = jeab_reminder_product_code((string) ($input['product'] ?? ''));
    $input['contact_id'] = $snap['contact_id'];
    $input['product'] = $code;
    $input['title'] = $person . ' – ' . jeab_reminder_product_label($code);
    $input['customer'] = '';
    $input['customer_name'] = '';
    $input['email'] = '';
    $input['phone'] = '';
    $input['address'] = '';
    $input['ort'] = '';
    $input['postal'] = '';

    return $input;
}

function jeab_reminder_create_show(JeabStore $store, array $row): void
{
    $GLOBALS['_JEAB_REMINDER_SHOWN'] = jeab_filter_reminders(
        [$row],
        jeab_reminder_view(),
        jeab_reminder_tag_filter(),
        trim((string) ($_GET['q'] ?? ''))
    ) !== [];
    $GLOBALS['_JEAB_REMINDER_COUNTS'] = jeab_reminder_view_counts($store);
    foreach (jeab_reminder_saved_query($row) as $key => $value) {
        if ($value === null || $value === '') {
            unset($_GET[$key]);
        } else {
            $_GET[$key] = (string) $value;
        }
    }
}

function jeab_reminder_create_action(JeabStore $store, array $input, ?bool $bot = null): array
{
    if ($bot === null) {
        $bot = jeab_reminder_bot_request($input);
    }
    $input = jeab_reminder_create_prepare($store, $input);
    if (isset($input['_existing']) && is_array($input['_existing'])) {
        $row = $input['_existing'];
        if (!$bot) {
            jeab_reminder_create_show($store, $row);
        }

        return $row;
    }

    $title = trim((string) ($input['title'] ?? ''));
    $nameKeys = ['name', 'namn', 'customer', 'customer_name'];
    $name = jeab_reminder_input_text($input, $nameKeys);
    if (jeab_reminder_placeholder_name($title) || jeab_reminder_placeholder_name($name)) {
        throw new InvalidArgumentException('Skriv ett riktigt namn.');
    }

    if ($bot) {
        if ($name === '' && !jeab_reminder_has_input($input, $nameKeys) && $title !== '') {
            $name = $title;
        }
        if ($name === '') {
            throw new InvalidArgumentException('Skriv ett namn.');
        }
        if ($title === '') {
            $title = $name;
        }
    } elseif ($title === '') {
        throw new InvalidArgumentException('Skriv en påminnelse.');
    }

    $companyRaw = jeab_reminder_input_text($input, ['company', 'bolag', 'co']);
    $company = null;
    if ($companyRaw !== '') {
        $company = jeab_normalize_company($companyRaw);
        if ($company === null) {
            throw new InvalidArgumentException('Okänt bolag.');
        }
    }

    $who = jeab_reminder_who_text($input);
    $parts = jeab_reminder_who_parts($who);
    if ($company === null && $parts !== []) {
        $company = jeab_infer_company_from_names($parts);
    }
    $company = $company ?? jeab_selected_company();
    $assignees = $parts === [] ? [] : jeab_parse_assignees($parts, $company);
    if ($parts !== []) {
        $unique = [];
        foreach ($parts as $part) {
            $key = function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part);
            $unique[$key] = true;
        }
        if (count($assignees) !== count($unique)) {
            throw new InvalidArgumentException('Okänd ansvarig.');
        }
    }

    $phone = null;
    foreach (['phone', 'telefon', 'tel', 'mobil'] as $key) {
        if (array_key_exists($key, $input) && !is_array($input[$key])) {
            $phone = $input[$key];
            break;
        }
    }
    $email = jeab_reminder_input_text($input, ['email', 'e-post', 'epost', 'mail']);
    $address = jeab_reminder_input_text($input, ['address', 'adress']);
    $note = jeab_reminder_input_text($input, ['note', 'notes', 'comment', 'kommentar', 'anteckning']);
    $tags = $input['tags'] ?? $input['taggar'] ?? [];

    $details = [
        'customer' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'notes' => $note,
        'who' => $assignees !== [] ? jeab_assignees_join($assignees) : '',
        'company' => $company,
        'tags' => $tags,
    ];
    if (array_key_exists('flagged', $input)) {
        $details['flagged'] = $input['flagged'];
    }
    if (!empty($input['webshop_order'])) {
        $details['webshop_order'] = true;
    }
    if (!$bot) {
        $details['lookup'] = false;
    }
    $details = array_merge($details, jeab_reminder_hs_payload_fields($input));
    $threadId = trim((string) ($input['thread_id'] ?? ''));
    if ($threadId !== '') {
        $details['thread_id'] = $threadId;
    }
    $contactId = trim((string) ($input['contact_id'] ?? ''));
    if ($contactId !== '') {
        $details['contact_id'] = $contactId;
    }
    $postal = jeab_reminder_input_text($input, ['postal', 'postnummer']);
    $ort = jeab_reminder_input_text($input, ['ort', 'city']);
    if ($postal !== '') {
        $details['postal'] = $postal;
    }
    if ($ort !== '') {
        $details['ort'] = $ort;
    }

    $row = jeab_create_reminder(
        $store,
        $title,
        jeab_reminder_due_from_create($input, $bot),
        $note !== '' ? $note : null,
        null,
        $details
    );
    if ($threadId !== '' && is_array($row) && function_exists('jeab_lead_reminder_append')) {
        $linked = jeab_lead_reminder_append($row, $threadId);
        if (is_array($linked)) {
            $row = $linked;
        }
    }
    if (!$bot && is_array($row)) {
        jeab_reminder_create_show($store, $row);
    }
    return $row;
}

/**
 * Adressen tillbaka efter «Sparad». Raden öppnas och filtret vidgas så den finns i listan.
 *
 * @param array<string, mixed> $row
 * @return array{open:?int,co:string,who:string,v:string,tag:string,q:string}
 */
function jeab_reminder_saved_query(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $company = jeab_reminder_company($row);
    $who = jeab_reminder_who_current($row);
    $view = jeab_reminder_open_tab($row);
    $tags = jeab_parse_tags($row['tags'] ?? []);
    $tagFilter = jeab_reminder_tag_filter();
    $tag = jeab_reminder_tag_query($tagFilter);
    if (jeab_filter_reminders([$row], $view, $tagFilter, '') === []) {
        $tag = $tags[0] ?? 'all';
    }
    return [
        'open' => $id > 0 ? $id : null,
        'co' => $company,
        'who' => $who !== '' ? $who : 'all',
        'v' => $view,
        'tag' => $tag,
        'q' => '',
    ];
}

/** Tråden som POST:ades när påminnelsen skapades från inkorgen. */
function jeab_reminder_create_from_thread(): bool
{
    return trim((string) ($_POST['thread_id'] ?? '')) !== '';
}

/**
 * Stängt ärende att öppna igen. Föredrar tråden som påminnelsen skapades från.
 *
 * @return string
 */
function jeab_reminder_create_closed_tid(): string
{
    if (!jeab_reminder_create_from_thread()) {
        return '';
    }
    $closed = $GLOBALS['_JEAB_REMINDER_CLOSED_THREADS'] ?? [];
    if (!is_array($closed) || $closed === []) {
        return '';
    }
    $from = trim((string) ($_POST['thread_id'] ?? ''));
    if ($from !== '' && in_array($from, $closed, true)) {
        return $from;
    }
    $first = trim((string) ($closed[0] ?? ''));

    return $first;
}

/** @param array<string, mixed> $row */
function jeab_reminder_create_flash(array $row): string
{
    $base = !empty($row['reused']) ? 'Befintlig påminnelse.' : 'Sparad.';
    if (jeab_reminder_create_closed_tid() === '') {
        return $base;
    }

    return $base . ' Ärendet stängt.';
}

/**
 * Returadress efter skapa, med flash och ev. stängt ärende.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_reminder_create_query_extra(array $row): array
{
    $extra = jeab_reminder_saved_query($row);
    $extra['flash'] = jeab_reminder_create_flash($row);
    $tid = jeab_reminder_create_closed_tid();
    if ($tid !== '') {
        $extra['stangd'] = $tid;
    }

    return $extra;
}

/**
 * Explicit Help Scout-id från bot/hook (hs_id, helpscout_id, conversation_id).
 *
 * @return array<string, mixed>
 */
function jeab_reminder_hs_payload_fields(array $input): array
{
    $sources = [$input];
    if (is_array($input['data'] ?? null)) {
        $sources[] = $input['data'];
    }
    $out = [];
    foreach (['hs_id', 'helpscout_id', 'conversation_id'] as $key) {
        foreach ($sources as $source) {
            if (!array_key_exists($key, $source) || is_array($source[$key]) || array_key_exists($key, $out)) {
                continue;
            }
            $out[$key] = $source[$key];
        }
    }
    return $out;
}

function jeab_reminder_hs_id_ok(string $digits): ?string
{
    if (preg_match('/^\d{1,12}$/', $digits) !== 1) {
        return null;
    }
    $digits = ltrim($digits, '0');
    if ($digits === '' || strlen($digits) > 12) {
        return null;
    }
    return $digits;
}

/** @return list<string> */
function jeab_reminder_hs_ids_from_text(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $found = [];
    $add = static function (string $digits) use (&$found): void {
        $id = jeab_reminder_hs_id_ok($digits);
        if ($id !== null && !in_array($id, $found, true)) {
            $found[] = $id;
        }
    };

    // HS#24563, HS #24563, HS# 24563
    if (preg_match_all('/\bHS\s*#\s*(\d{1,12})\b/iu', $text, $matches)) {
        foreach ($matches[1] as $digits) {
            $add((string) $digits);
        }
    }
    // Help Scout #24563, HelpScout #24563, help-scout conversation #24563
    if (preg_match_all('/\bhelp[\s-]*scout\b(?:\s*(?:conversation|konversation))?(?:\s*(?:nr|nummer|id))?\s*#\s*(\d{1,12})\b/iu', $text, $matches)) {
        foreach ($matches[1] as $digits) {
            $add((string) $digits);
        }
    }
    // https://secure.helpscout.net/conversation/24563/...
    if (preg_match_all('~helpscout\.net/(?:[\w./-]*?)conversation/(\d{1,12})\b~iu', $text, $matches)) {
        foreach ($matches[1] as $digits) {
            $add((string) $digits);
        }
    }
    // #24563 only when HS / Help Scout sits within 40 characters.
    if (preg_match_all('/(?:\bHS\b|\bhelp[\s-]*scout\b).{0,40}#\s*(\d{1,12})\b|#\s*(\d{1,12})\b.{0,40}(?:\bHS\b|\bhelp[\s-]*scout\b)/ius', $text, $matches)) {
        foreach ($matches[1] as $digits) {
            if ($digits !== '') {
                $add((string) $digits);
            }
        }
        foreach ($matches[2] as $digits) {
            if ($digits !== '') {
                $add((string) $digits);
            }
        }
    }

    return $found;
}

function jeab_reminder_hs_id_from_value(mixed $raw): ?string
{
    if (is_int($raw)) {
        return $raw > 0 ? jeab_reminder_hs_id_ok((string) $raw) : null;
    }
    if (is_float($raw)) {
        if ($raw <= 0 || floor($raw) !== $raw) {
            return null;
        }
        return jeab_reminder_hs_id_ok((string) (int) $raw);
    }
    $text = trim((string) $raw);
    if ($text === '') {
        return null;
    }
    if (preg_match('/^\d{1,12}$/', $text) === 1) {
        return jeab_reminder_hs_id_ok($text);
    }
    $fromText = jeab_reminder_hs_ids_from_text($text);
    if ($fromText !== []) {
        return $fromText[0];
    }
    if (preg_match('~/conversation/(\d{1,12})\b~', $text, $match) === 1) {
        return jeab_reminder_hs_id_ok($match[1]);
    }
    return null;
}

/** @return list<string> */
function jeab_reminder_hs_explicit_ids(array $input): array
{
    $ids = [];
    foreach (jeab_reminder_hs_payload_fields($input) as $value) {
        $id = jeab_reminder_hs_id_from_value($value);
        if ($id !== null && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/** @return list<string> */
function jeab_reminder_hs_ids_for_create(?string $note, array $details): array
{
    $ids = jeab_reminder_hs_explicit_ids($details);
    $texts = [];
    if ($note !== null && trim($note) !== '') {
        $texts[] = $note;
    }
    foreach (['note', 'notes', 'comment', 'kommentar', 'anteckning'] as $key) {
        if (!array_key_exists($key, $details) || is_array($details[$key])) {
            continue;
        }
        $text = trim((string) $details[$key]);
        if ($text !== '') {
            $texts[] = $text;
        }
    }
    foreach ($texts as $text) {
        foreach (jeab_reminder_hs_ids_from_text($text) as $id) {
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
    }
    return $ids;
}

function jeab_reminder_note_line_key(string $line): string
{
    $line = trim($line);
    if ($line === '') {
        return '';
    }
    $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
    return function_exists('mb_strtolower') ? mb_strtolower($line, 'UTF-8') : strtolower($line);
}

function jeab_reminder_note_with_hs(?string $note, array $ids): ?string
{
    $note = trim((string) $note);
    $present = [];
    foreach (jeab_reminder_hs_ids_from_text($note) as $id) {
        $present['hs:' . $id] = true;
    }
    $missing = [];
    foreach ($ids as $id) {
        if (!isset($present['hs:' . $id])) {
            $missing[] = 'HS#' . $id;
        }
    }
    if ($missing === []) {
        return $note === '' ? null : $note;
    }
    $suffix = implode("\n", $missing);
    return $note === '' ? $suffix : $note . "\n" . $suffix;
}

/** Host som är helpscout.net eller en underdomän, med eller utan schema. */
function jeab_reminder_hs_url_regex(): string
{
    return '(?<![\w@./])(?:(?:https?:)?//)?(?:[a-z0-9-]+\.)*helpscout\.net(?=[:/\?#]|[^\w.]|$)[^\s<>"\']*';
}

function jeab_reminder_note_has_hs_url(string $text): bool
{
    if ($text === '') {
        return false;
    }
    return preg_match('~' . jeab_reminder_hs_url_regex() . '~iu', $text) === 1;
}

/**
 * Tar bort Help Scout-adresser och en ledande HS:-etikett (även « | HS: »).
 * HS# lämnas kvar. Samma text en gång till ändras inte.
 */
function jeab_reminder_strip_hs_links(string $text): string
{
    if ($text === '') {
        return '';
    }
    $clean = preg_replace_callback(
        '~' . jeab_reminder_hs_url_regex() . '~iu',
        static function (array $match): string {
            $url = (string) ($match[0] ?? '');
            if (preg_match('/^(.*?)([.,;:!?)\\]]+)$/u', $url, $parts) === 1) {
                $core = (string) ($parts[1] ?? '');
                if ($core !== '' && preg_match('/helpscout\.net/iu', $core) === 1) {
                    return (string) ($parts[2] ?? '');
                }
            }
            return '';
        },
        $text
    );
    if (!is_string($clean)) {
        $clean = $text;
    }
    $clean = preg_replace('~\s*(?:\|\s*HS\s*:\s*)+~iu', ' ', $clean) ?? $clean;
    $clean = preg_replace('~(^|\R)\s*(?:HS\s*:\s*)+~iu', '$1', $clean) ?? $clean;
    $clean = preg_replace('~\s+\bHS\s*:\s*$~imu', '', $clean) ?? $clean;
    $clean = preg_replace('~\(\s*\)~u', '', $clean) ?? $clean;
    $clean = preg_replace('~<\s*>~u', '', $clean) ?? $clean;
    $clean = preg_replace('/[ \t]+([.,;:!?])/u', '$1', $clean) ?? $clean;
    $clean = preg_replace("/[ \t]{2,}/u", ' ', $clean) ?? $clean;
    $clean = preg_replace("/[ \t]+\n/u", "\n", $clean) ?? $clean;
    $lines = preg_split('/\R/u', $clean) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        $line = trim($line, " \t|");
        if ($line !== '') {
            $kept[] = $line;
        }
    }
    return trim(implode("\n", $kept));
}

/**
 * Läser HS-numret ur originalet, tar bort Help Scout-adressen och skriver
 * HS# om numret inte längre syns i texten.
 *
 * @param list<string> $extraIds
 */
function jeab_reminder_note_for_save(?string $note, array $extraIds = []): ?string
{
    $raw = trim((string) $note);
    $ids = $raw === '' ? [] : jeab_reminder_hs_ids_from_text($raw);
    foreach ($extraIds as $extra) {
        $id = jeab_reminder_hs_id_ok((string) $extra);
        if ($id !== null && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    $stripped = jeab_reminder_strip_hs_links($raw);
    if (function_exists('jeab_text_drop_duplicate_via_lines')) {
        $stripped = jeab_text_drop_duplicate_via_lines($stripped);
    }
    return jeab_reminder_note_with_hs($stripped === '' ? null : $stripped, $ids);
}

function jeab_reminder_clip_preview(string $text): string
{
    $text = trim($text);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, 80, 'UTF-8');
    }
    return substr($text, 0, 80);
}

function jeab_reminder_strip_hs_wants_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];
    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

/**
 * Hook reminders.strip_hs_links. dry=1 är standard och skriver inget.
 * dry=0 skriver om bara anteckningar som innehåller en Help Scout-adress.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_strip_hs_links_run(JeabStore $store, array $input = []): array
{
    $dry = jeab_reminder_strip_hs_wants_dry($input);
    $ids = [];
    $preview = [];
    $updated = 0;
    foreach ($store->listReminders(true) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $before = jeab_reminder_notes_text($row);
        if (!jeab_reminder_note_has_hs_url($before)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $after = jeab_reminder_note_for_save($before);
        $ids[] = $id;
        $preview[] = [
            'id' => $id,
            'before' => jeab_reminder_clip_preview($before),
            'after' => jeab_reminder_clip_preview((string) $after),
        ];
        if ($dry) {
            continue;
        }
        if (($after ?? '') === trim($before)) {
            continue;
        }
        jeab_update_reminder($store, $id, ['note' => $before]);
        $updated++;
    }
    sort($ids);
    usort($preview, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
    return [
        'ok' => true,
        'action' => 'reminders.strip_hs_links',
        'dry' => $dry ? 1 : 0,
        'ids' => $ids,
        'preview' => $preview,
        'updated' => $updated,
    ];
}

/**
 * reminders.lookup_hsi. Bara läsning. thread_id eller hs-id.
 * Svaret har id, tråd, Help Scout-id och träff. Ingen kundtext.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_lookup_hsi(JeabStore $store, array $input): array
{
    $thread = trim((string) ($input['thread_id'] ?? $input['thread'] ?? ''));
    $hsRaw = trim((string) ($input['hs_id'] ?? $input['hs'] ?? ''));
    if ($hsRaw === '' && preg_match('/^\d{1,12}$/', $thread) === 1) {
        $hsRaw = $thread;
        $thread = '';
    }
    $hs = '';
    if ($hsRaw !== '') {
        $digits = preg_replace('/\D+/', '', $hsRaw) ?? '';
        $hs = jeab_reminder_hs_id_ok($digits) ?? '';
        if ($hs === '' && $thread === '') {
            return ['ok' => false, 'error' => 'hs-id ogiltigt', 'reminders' => []];
        }
    }
    if ($thread === '' && $hs === '') {
        return ['ok' => false, 'error' => 'thread_id eller hs-id krävs', 'reminders' => []];
    }
    $rows = [];
    foreach (jeab_reminders_every($store) as $row) {
        if (!is_array($row) || (int) ($row['id'] ?? 0) < 1) {
            continue;
        }
        $note = (string) ($row['note'] ?? '');
        $title = (string) ($row['title'] ?? '');
        $blob = $note . "\n" . $title;
        $matched = [];
        $rowThread = trim((string) ($row['thread_id'] ?? ''));
        if ($thread !== '' && $rowThread === $thread) {
            $matched[] = 'thread_id';
        }
        if ($thread !== '' && function_exists('jeab_lead_reminder_has_thread') && jeab_lead_reminder_has_thread($blob, $thread)) {
            $matched[] = 'note';
        }
        $ids = jeab_reminder_hs_ids_from_text($blob);
        if ($hs !== '' && in_array($hs, $ids, true)) {
            $matched[] = 'hs';
        }
        if ($matched === []) {
            continue;
        }
        $rows[] = [
            'id' => (int) $row['id'],
            'thread_id' => $rowThread,
            'hs_ids' => $ids,
            'company' => (string) ($row['company'] ?? ''),
            'closed' => trim((string) ($row['completed_at'] ?? '')) !== '',
            'matched' => array_values(array_unique($matched)),
        ];
    }
    usort($rows, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
    return [
        'ok' => true,
        'action' => 'reminders.lookup_hsi',
        'dry' => 1,
        'read_only' => true,
        'count' => count($rows),
        'reminders' => $rows,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_lookup_hsi_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_reminders_lookup_hsi($store, $input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}

function jeab_reminders_strip_hs_links_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_reminders_strip_hs_links_run($store, $input);
    $result['status'] = 200;
    return $result;
}

function jeab_reminder_merge_hs_note(?string $existing, ?string $incoming): ?string
{
    $existing = trim((string) $existing);
    $incoming = trim((string) $incoming);
    if ($incoming === '') {
        return $existing === '' ? null : $existing;
    }
    if ($existing === '') {
        return $incoming;
    }
    $have = [];
    foreach (preg_split('/\R/u', $existing) ?: [] as $line) {
        $key = jeab_reminder_note_line_key((string) $line);
        if ($key !== '') {
            $have[$key] = true;
        }
    }
    $add = [];
    foreach (preg_split('/\R/u', $incoming) ?: [] as $line) {
        $line = trim((string) $line);
        $key = jeab_reminder_note_line_key($line);
        if ($key === '' || isset($have[$key])) {
            continue;
        }
        $have[$key] = true;
        $add[] = $line;
    }
    if ($add === []) {
        return $existing;
    }
    return $existing . "\n" . implode("\n", $add);
}

function jeab_reminder_find_open_by_hs(JeabStore $store, array $ids): ?array
{
    if ($ids === []) {
        return null;
    }
    $open = [];
    foreach ($store->listReminders(false) as $row) {
        if (!empty($row['completed_at'])) {
            continue;
        }
        $rowIds = jeab_reminder_hs_ids_from_text(jeab_reminder_notes_text($row));
        if ($rowIds === []) {
            continue;
        }
        $keyed = [];
        foreach ($rowIds as $rowId) {
            $keyed['hs:' . $rowId] = true;
        }
        $open[] = ['row' => $row, 'ids' => $keyed];
    }
    foreach ($ids as $id) {
        $best = null;
        foreach ($open as $item) {
            if (!isset($item['ids']['hs:' . $id])) {
                continue;
            }
            if ($best === null || (int) $item['row']['id'] < (int) $best['id']) {
                $best = $item['row'];
            }
        }
        if ($best !== null) {
            return $best;
        }
    }
    return null;
}

function jeab_reminder_reuse_open_hs(JeabStore $store, array $existing, ?string $incomingNote): array
{
    $current = trim((string) ($existing['note'] ?? ''));
    $merged = jeab_reminder_merge_hs_note($current !== '' ? $current : null, $incomingNote);
    $next = trim((string) ($merged ?? ''));
    if ($next === $current) {
        return $existing;
    }
    return jeab_update_reminder($store, (int) $existing['id'], ['note' => $merged]);
}

function jeab_create_reminder(
    JeabStore $store,
    string $title,
    ?string $dueAt = null,
    ?string $note = null,
    ?int $bookingId = null,
    array $details = []
): array {
    if (function_exists('jeab_inkorg_reclassify_locked') && jeab_inkorg_reclassify_locked()) {
        throw new RuntimeException('omklassning skapar ingen påminnelse');
    }
    $title = trim($title);
    if ($title === '') {
        throw new InvalidArgumentException('Skriv en påminnelse.');
    }
    $postalIn = trim((string) ($details['postal'] ?? ''));
    $ortIn = array_key_exists('ort', $details) ? trim((string) $details['ort']) : '';
    $hsIds = $bookingId !== null && $bookingId > 0
        ? []
        : jeab_reminder_hs_ids_for_create($note, $details);
    $ownOrder = !empty($details['webshop_order']);
    $contactId = trim((string) ($details['contact_id'] ?? ''));
    $threadId = trim((string) ($details['thread_id'] ?? ''));
    $threadIdExplicit = $threadId;
    $requirePostal = !empty($details['require_postal']);
    $lookup = !array_key_exists('lookup', $details)
        || !($details['lookup'] === false || $details['lookup'] === 0 || (string) $details['lookup'] === '0');
    $testSubmission = !empty($details['is_test']) || !empty($details['test'])
        || (function_exists('jeab_test_submission_marked') && jeab_test_submission_marked($details));
    $details = jeab_reminder_details_from_input($details);
    if ($contactId !== '') {
        $details['contact_id'] = $contactId;
    }
    if ($note !== null && $note !== '' && $details['note'] === null) {
        $details['note'] = $note;
    }
    $blockedHs = $hsIds !== []
        && function_exists('jeab_ordrar_deleted_blocks_hs')
        && jeab_ordrar_deleted_blocks_hs($hsIds);
    if ($blockedHs && function_exists('jeab_ordrar_tags_without_vunnen')) {
        $details['tags'] = jeab_ordrar_tags_without_vunnen($details['tags'] ?? []);
    }
    $details['note'] = jeab_reminder_note_for_save($details['note'], $hsIds);
    if ($hsIds !== []) {
        $existing = jeab_reminder_find_open_by_hs($store, $hsIds);
        if ($existing) {
            $existingId = (int) ($existing['id'] ?? 0);
            if ($existingId > 0
                && function_exists('jeab_ordrar_deleted_blocks')
                && function_exists('jeab_ordrar_tags_without_vunnen')
                && jeab_ordrar_deleted_blocks($existingId, $existing)
                && in_array('vunnen', jeab_parse_tags($existing['tags'] ?? []), true)
            ) {
                $existing = jeab_update_reminder($store, $existingId, [
                    'tags' => jeab_ordrar_tags_without_vunnen($existing['tags'] ?? []),
                ]);
            }
            return jeab_reminder_reuse_open_hs($store, $existing, $details['note']);
        }
    }
    if ($threadId === '') {
        $threadId = jeab_reminder_thread_from_text((string) ($details['note'] ?? ''));
    }
    if (($bookingId === null || $bookingId <= 0) && $threadIdExplicit !== '') {
        $byThread = jeab_reminder_for_thread($threadIdExplicit);
        if (!is_array($byThread) && function_exists('jeab_lead_find_reminder')) {
            $byThread = jeab_lead_find_reminder($threadId, '');
        }
        if (is_array($byThread) && (int) ($byThread['id'] ?? 0) > 0) {
            $byThread['reused'] = true;

            return $byThread;
        }
    }
    // En ny betald order är ett eget köp, även när kunden redan har samma produkt.
    if (!$ownOrder && ($bookingId === null || $bookingId <= 0)) {
        $same = jeab_reminder_find_customer_product($store, $title, $details);
        if (is_array($same) && (int) ($same['id'] ?? 0) > 0 && $requirePostal && !jeab_reminder_same_party($same, $details, $postalIn, (string) ($details['address'] ?? ''))) {
            $same = null;
        }
        if (is_array($same) && (int) ($same['id'] ?? 0) > 0) {
            $tid = jeab_reminder_thread_from_text((string) ($details['note'] ?? ''));
            if ($tid !== '' && function_exists('jeab_lead_reminder_append')) {
                $linked = jeab_lead_reminder_append($same, $tid);
                if (is_array($linked)) {
                    $same = $linked;
                }
            } elseif ($tid !== '') {
                jeab_reminder_remember_thread($store, (int) $same['id'], $tid);
            }
            $same['reused'] = true;
            if (function_exists('jeab_inkorg_close_open_for_reminder')) {
                try {
                    jeab_inkorg_close_open_for_reminder($same, true);
                } catch (Throwable $e) {
                    unset($e);
                }
            }
            return $same;
        }
    }
    if ($dueAt === null || trim($dueAt) === '') {
        $dueAt = jeab_today_start();
    }
    $place = jeab_address_parts((string) ($details['address'] ?? ''));
    if ($ortIn === '') {
        $ortIn = $place['city'];
    } else {
        $ortIn = jeab_address_town($ortIn);
    }
    if ($postalIn === '') {
        $postalIn = $place['postal'];
    } elseif (function_exists('jeab_kontakt_postal')) {
        $normalizedPostal = jeab_kontakt_postal($postalIn);
        if ($normalizedPostal !== '') {
            $postalIn = $normalizedPostal;
        }
    }
    if (function_exists('jeab_contacts_strict_prepare_link')) {
        $contactId = jeab_contacts_strict_prepare_link('reminder', $contactId, [
            'customer_name' => (string) ($details['link_customer_name'] ?? $details['customer_name'] ?? ''),
            'email' => (string) ($details['link_email'] ?? $details['email'] ?? ''),
            'phone' => (string) ($details['link_phone'] ?? $details['phone'] ?? ''),
            'address' => (string) ($details['link_address'] ?? $details['address'] ?? ''),
            'ort' => $ortIn,
            'postal' => $postalIn,
            'company' => (string) ($details['company'] ?? ''),
            'thread_id' => $threadId,
            'lookup' => $lookup,
            'require_postal' => $requirePostal,
        ]);
    }
    $storeName = $details['customer_name'];
    $storeEmail = $details['email'];
    $storePhone = $details['phone'];
    $storeAddress = $details['address'];
    $storeOrt = $ortIn !== '' ? $ortIn : null;
    $storePostal = $postalIn !== '' ? $postalIn : null;
    if (function_exists('jeab_contacts_copies_hold') && jeab_contacts_copies_hold() && $contactId !== '') {
        $storeName = null;
        $storeEmail = null;
        $storePhone = null;
        $storeAddress = null;
        $storeOrt = null;
        $storePostal = null;
    }
    $now = jeab_now();
    $row = $store->insertReminder([
        'title' => $title,
        'customer_name' => $storeName,
        'email' => $storeEmail,
        'phone' => $storePhone,
        'address' => $storeAddress,
        'ort' => $storeOrt,
        'postal' => $storePostal,
        'contact_id' => $contactId !== '' ? $contactId : null,
        'assignees' => $details['assignees'],
        'assignee' => $details['assignees'] ? jeab_assignees_join($details['assignees']) : null,
        'company' => $details['company'],
        'person' => $details['person'],
        'flagged' => !empty($details['flagged']),
        'tags' => $details['tags'],
        'note' => $details['note'],
        'due_at' => $dueAt,
        'completed_at' => null,
        'booking_id' => $bookingId,
        'thread_id' => $threadId !== '' ? $threadId : null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    unset($GLOBALS['_JEAB_PURCHASE_KIND_MAP']);
    $row = jeab_reminder_bind_contact($store, $row, [
        'contact_id' => $contactId,
        'name' => (string) ($details['link_customer_name'] ?? $details['customer_name'] ?? ''),
        'email' => (string) ($details['link_email'] ?? $details['email'] ?? ''),
        'phone' => (string) ($details['link_phone'] ?? $details['phone'] ?? ''),
        'address' => (string) ($details['link_address'] ?? $details['address'] ?? ''),
        'city' => $ortIn,
        'postal_code' => $postalIn,
        'company' => (string) ($details['company'] ?? ''),
        'thread_id' => $threadId,
        'lookup' => $lookup,
        'require_postal' => $requirePostal,
    ]);
    if (trim((string) ($row['contact_id'] ?? '')) === '' && function_exists('jeab_contacts_strict_assert_link') && jeab_contacts_strict() && function_exists('jeab_contacts_sole') && jeab_contacts_sole()) {
        $newId = (int) ($row['id'] ?? 0);
        if ($newId > 0) {
            try {
                $store->deleteReminders([$newId]);
            } catch (Throwable) {
            }
        }
        jeab_contacts_strict_assert_link('reminder', '');
    }
    if ($details['assignees']) {
        jeab_remember_assignees($details['assignees']);
    }
    if (!$testSubmission) {
        $hookRow = $row;
        if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_paint_reminder')) {
            $hookRow = jeab_contacts_sole_paint_reminder($row);
        }
        jeab_fire_webhook($store, 'reminder.created', jeab_reminder_payload($hookRow));
    }
    if (function_exists('jeab_inkorg_close_open_for_reminder')) {
        try {
            jeab_inkorg_close_open_for_reminder($row, true);
        } catch (Throwable $e) {
            unset($e);
        }
    }
    return $row;
}

/**
 * Datum från due-formuläret. Ett angivet datum, även ett som redan passerat,
 * sparas som det är. Tomt fält rensar tiden. Idag ersätter aldrig ett gammalt datum.
 */
/**
 * Väggklocka som servern redan lagrat (Europe/Stockholm), utan att ett Z eller
 * en offset flyttar timmarna. «2026-10-23T12:54:00.000Z» förblir 12:54.
 */
function jeab_reminder_due_wall(string $raw): string
{
    $raw = trim(str_replace('T', ' ', $raw));
    if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})(?::(\d{2}))?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/', $raw, $m) === 1) {
        return $m[1] . ' ' . $m[2] . ':' . ($m[3] ?? '00');
    }
    return trim($raw);
}

function jeab_reminder_due_posted(string $raw): ?string
{
    $raw = jeab_reminder_due_wall($raw);
    if ($raw === '') {
        return null;
    }
    $parsed = jeab_parse_reminder_due($raw);
    if ($parsed === null) {
        throw new InvalidArgumentException('Ogiltigt datum.');
    }
    return $parsed;
}

/**
 * type=date skickar bara dagen och skulle nollställa en 2h-knuff.
 * Klockslaget från den sparade tiden följer med till det nya datumet.
 */
function jeab_reminder_due_keep_clock(?string $posted, ?string $previous): ?string
{
    if ($posted === null || $previous === null) {
        return $posted;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $posted)) {
        return $posted;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2} (\d{2}:\d{2}:\d{2})$/', trim($previous), $clock)) {
        return $posted;
    }
    if ($clock[1] === '00:00:00') {
        return $posted;
    }
    return substr($posted, 0, 10) . ' ' . $clock[1];
}

/** keep_clock=0 betyder att den skickade tiden ska sparas som den är. */
function jeab_reminder_keep_clock_skipped(array $post): bool
{
    return array_key_exists('keep_clock', $post) && (string) $post['keep_clock'] === '0';
}

/**
 * Datumfältet behåller klockslaget. En knuff skickar keep_clock=0 så 02:00
 * inte klistras tillbaka på midnatt.
 */
function jeab_reminder_due_from_post(?string $posted, ?string $previous, array $post): ?string
{
    if (jeab_reminder_keep_clock_skipped($post)) {
        return $posted;
    }

    return jeab_reminder_due_keep_clock($posted, $previous);
}

/**
 * reminder.due. Bara due_at. completed_at ignoreras (H-REM-1).
 *
 * @param array<string, mixed> $post
 * @param array<string, mixed>|null $existing
 * @return array{due_at:?string}
 */
function jeab_reminder_due_fields(array $post, ?array $existing): array
{
    $duePosted = jeab_reminder_due_posted((string) ($post['due_at'] ?? ''));
    $duePosted = jeab_reminder_due_from_post(
        $duePosted,
        is_array($existing) ? (string) ($existing['due_at'] ?? '') : null,
        $post
    );

    return ['due_at' => $duePosted];
}

/** Tom sträng = öppen. Annars exakt den completed_at som skickades. */
function jeab_reminder_completed_posted(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $parsed = jeab_parse_datetime($raw);
    if ($parsed === null) {
        throw new InvalidArgumentException('Ogiltig status.');
    }
    return $parsed;
}

function jeab_update_reminder(JeabStore $store, int $id, array $fields, bool $overwriteContact = false): array
{
    $row = $store->getReminder($id);
    if (!$row) {
        throw new InvalidArgumentException('Påminnelsen finns inte.');
    }
    jeab_reminder_require_actor($row);
    if (array_key_exists('company', $fields) || array_key_exists('co', $fields)) {
        $nextCompany = jeab_normalize_company($fields['company'] ?? $fields['co'] ?? null) ?? jeab_reminder_company($row);
        if (function_exists('jeab_company_allowed') && !jeab_company_allowed($nextCompany)) {
            throw new InvalidArgumentException('Inte behörig.');
        }
    }

    $title = array_key_exists('title', $fields) ? trim((string) $fields['title']) : $row['title'];
    if ($title === '') {
        throw new InvalidArgumentException('Skriv en påminnelse.');
    }
    $details = jeab_reminder_details_from_input($fields);
    $lift = ['phone' => '', 'email' => '', 'address' => ''];
    if (array_key_exists('note', $fields) || array_key_exists('notes', $fields)) {
        $lift = jeab_reminder_note_labeled_values((string) ($row['note'] ?? ''));
        $fromNext = jeab_reminder_note_labeled_values((string) ($details['note'] ?? ''));
        foreach (['phone', 'email', 'address'] as $key) {
            if ($lift[$key] === '' && $fromNext[$key] !== '') {
                $lift[$key] = $fromNext[$key];
            }
        }
    }
    $note = array_key_exists('note', $fields) || array_key_exists('notes', $fields)
        ? jeab_reminder_restore_hidden_lines((string) ($row['note'] ?? ''), jeab_reminder_note_for_save($details['note']))
        : ($row['note'] ?? null);
    $phoneNow = array_key_exists('phone', $fields) ? trim((string) ($details['link_phone'] ?? $details['phone'] ?? '')) : trim((string) ($row['phone'] ?? ''));
    $emailNow = array_key_exists('email', $fields) ? trim((string) ($details['link_email'] ?? $details['email'] ?? '')) : trim((string) ($row['email'] ?? ''));
    $addressNow = array_key_exists('address', $fields) ? trim((string) ($details['link_address'] ?? $details['address'] ?? '')) : trim((string) ($row['address'] ?? ''));
    if ($phoneNow === '' && $lift['phone'] !== '') {
        try {
            $phoneNow = (string) (jeab_normalize_reminder_phone($lift['phone']) ?? $lift['phone']);
        } catch (InvalidArgumentException) {
            $phoneNow = $lift['phone'];
        }
    }
    if (jeab_reminder_email_address($emailNow) === '') {
        if ($lift['email'] !== '') {
            $emailNow = $lift['email'];
        } elseif (preg_match('/mail:\s*https?:|inkorg/iu', $emailNow) === 1) {
            $emailNow = '';
        }
    }
    if ($addressNow === '' && $lift['address'] !== '') {
        $addressNow = $lift['address'];
    }
    $dueAt = array_key_exists('due_at', $fields) ? $fields['due_at'] : $row['due_at'];
    if (array_key_exists('assignees', $fields) || array_key_exists('assignee', $fields)) {
        $names = $details['assignees'];
    } else {
        $names = jeab_assignees_from_row($row);
    }
    $linkedContact = jeab_reminder_linked_contact($row);
    $freezeCustomer = is_array($linkedContact);
    if ($freezeCustomer) {
        $patch = [];
        if (array_key_exists('customer', $fields) || array_key_exists('customer_name', $fields)) {
            $patch['name'] = trim((string) ($details['link_customer_name'] ?? $details['customer_name'] ?? ''));
        }
        if (array_key_exists('email', $fields)) {
            $patch['email'] = $emailNow;
        } elseif ($lift['email'] !== '' && function_exists('jeab_kontakt_row_email') && jeab_kontakt_row_email($linkedContact) === '') {
            $patch['email'] = $lift['email'];
        }
        if (array_key_exists('phone', $fields)) {
            $patch['phone'] = $phoneNow;
        } elseif ($lift['phone'] !== '' && function_exists('jeab_kontakt_row_phone') && jeab_kontakt_row_phone($linkedContact) === '') {
            try {
                $patch['phone'] = (string) (jeab_normalize_reminder_phone($lift['phone']) ?? '');
            } catch (InvalidArgumentException) {
                $patch['phone'] = $lift['phone'];
            }
        }
        if (array_key_exists('address', $fields)) {
            $parsed = jeab_address_parts($addressNow);
            $patch['street'] = $parsed['street'] !== '' ? $parsed['street'] : $addressNow;
            if ($parsed['city'] !== '') {
                $patch['city'] = $parsed['city'];
            }
            if ($parsed['postal'] !== '') {
                $patch['postal'] = $parsed['postal'];
            }
        } elseif ($lift['address'] !== '' && trim((string) ($linkedContact['street'] ?? '')) === '') {
            $parsed = jeab_address_parts($lift['address']);
            if ($parsed['street'] !== '') {
                $patch['street'] = $parsed['street'];
            }
            if ($parsed['city'] !== '') {
                $patch['city'] = $parsed['city'];
            }
            if ($parsed['postal'] !== '') {
                $patch['postal'] = $parsed['postal'];
            }
        }
        if (array_key_exists('ort', $fields)) {
            $patch['city'] = jeab_address_town(trim((string) $fields['ort']));
        }
        if (array_key_exists('postal', $fields)) {
            $patch['postal'] = trim((string) $fields['postal']);
        }
        $writeContact = $overwriteContact || (function_exists('jeab_contacts_sole') && jeab_contacts_sole());
        $patch = jeab_reminder_contact_fill_patch($linkedContact, $patch, $writeContact);
        if ($patch !== []) {
            jeab_reminder_save_contact_customer((string) ($linkedContact['id'] ?? ''), $patch);
        }
    }

    $payload = [
        'title' => $title,
        'customer_name' => array_key_exists('customer', $fields) || array_key_exists('customer_name', $fields)
            ? $details['customer_name']
            : ($row['customer_name'] ?? null),
        'email' => $emailNow !== '' ? $emailNow : null,
        'phone' => $phoneNow !== '' ? $phoneNow : null,
        'address' => $addressNow !== '' ? $addressNow : null,
        'ort' => array_key_exists('ort', $fields)
            ? ($fields['ort'] === null ? null : trim((string) $fields['ort']))
            : ($row['ort'] ?? null),
        'postal' => array_key_exists('postal', $fields)
            ? (trim((string) $fields['postal']) !== '' ? trim((string) $fields['postal']) : null)
            : ($row['postal'] ?? null),
        'assignees' => $names,
        'assignee' => $names ? jeab_assignees_join($names) : null,
        'company' => array_key_exists('company', $fields) || array_key_exists('co', $fields)
            ? $details['company']
            : jeab_reminder_company($row),
        'person' => array_key_exists('person', $fields) || array_key_exists('who', $fields) || array_key_exists('assignee', $fields) || array_key_exists('assignees', $fields)
            ? ($details['person'] ?? null)
            : ($row['person'] ?? null),
        'flagged' => array_key_exists('flagged', $fields) ? !empty($details['flagged']) : !empty($row['flagged']),
        'tags' => array_key_exists('tags', $fields) ? $details['tags'] : jeab_parse_tags($row['tags'] ?? []),
        'note' => $note,
        'due_at' => $dueAt,
        'updated_at' => jeab_now(),
        ...jeab_reminder_tap_counts(array_merge($row, $fields)),
    ];
    if (!$freezeCustomer && !array_key_exists('ort', $fields) && (array_key_exists('address', $fields) || array_key_exists('customer', $fields) || array_key_exists('customer_name', $fields))) {
        $probe = array_merge($row, $payload);
        unset($probe['ort']);
        $city = jeab_reminder_city_for_save($probe);
        if ($city !== '') {
            $payload['ort'] = $city;
            $last = $GLOBALS['_JEAB_ADDRESS_EXTRACT_LAST'] ?? null;
            if (is_array($last) && trim((string) ($payload['postal'] ?? '')) === '' && (string) ($last['postal'] ?? '') !== '' && (string) ($last['city'] ?? '') === $city) {
                $payload['postal'] = (string) $last['postal'];
            }
        }
    }
    if ($freezeCustomer) {
        $payload['customer_name'] = $row['customer_name'] ?? null;
        $payload['email'] = $row['email'] ?? null;
        $payload['phone'] = $row['phone'] ?? null;
        $payload['address'] = $row['address'] ?? null;
        $payload['ort'] = $row['ort'] ?? null;
        $payload['postal'] = $row['postal'] ?? null;
    }
    if (trim((string) ($row['thread_id'] ?? '')) === '' && trim((string) ($payload['thread_id'] ?? '')) === '') {
        $tid = jeab_reminder_thread_from_text((string) ($row['note'] ?? ''));
        if ($tid !== '') {
            $payload['thread_id'] = $tid;
        }
    }
    if (array_key_exists('completed_at', $fields)) {
        $done = $fields['completed_at'];
        $payload['completed_at'] = ($done === null || trim((string) $done) === '') ? null : (string) $done;
    }
    $beforeCompany = jeab_reminder_company($row);
    $assignTouch = array_key_exists('assignees', $fields) || array_key_exists('assignee', $fields) || array_key_exists('who', $fields);
    $row = $store->saveReminder($id, $payload);
    if ($assignTouch && $beforeCompany !== jeab_reminder_company($row)) {
        if (!function_exists('jeab_company_sync_after_reminder_assign')) {
            require_once __DIR__ . '/company_realign.php';
        }
        if (function_exists('jeab_company_sync_after_reminder_assign')) {
            jeab_company_sync_after_reminder_assign($row);
        }
    }
    unset($GLOBALS['_JEAB_PURCHASE_KIND_MAP']);
    if ($names) {
        jeab_remember_assignees($names);
    }
    jeab_fire_webhook($store, 'reminder.updated', jeab_reminder_payload($row));
    if ((array_key_exists('note', $fields) || array_key_exists('notes', $fields))
        && function_exists('jeab_purchase_get')
        && function_exists('jeab_ordrar_refresh_linked_booking_notes')
    ) {
        $purchase = jeab_purchase_get((int) $row['id']);
        if (is_array($purchase)) {
            jeab_ordrar_refresh_linked_booking_notes($purchase);
        }
    }
    return $row;
}

function jeab_toggle_flag(JeabStore $store, int $id): array
{
    $row = $store->getReminder($id);
    if (!$row) {
        throw new InvalidArgumentException('Påminnelsen finns inte.');
    }
    return jeab_update_reminder($store, $id, ['flagged' => empty($row['flagged']) ? '1' : '']);
}

function jeab_toggle_reminder(JeabStore $store, int $id, ?bool $completed = null): array
{
    $row = $store->getReminder($id);
    if (!$row) {
        throw new InvalidArgumentException('Påminnelsen finns inte.');
    }
    jeab_reminder_require_actor($row);
    $isDone = !empty($row['completed_at']);
    $shouldComplete = $completed ?? !$isDone;
    $row = $store->saveReminder($id, [
        'completed_at' => $shouldComplete ? jeab_now() : null,
        'updated_at' => jeab_now(),
    ]);
    $event = $shouldComplete ? 'reminder.completed' : 'reminder.updated';
    jeab_fire_webhook($store, $event, jeab_reminder_payload($row));
    if ($shouldComplete && (string) ($_POST['close_lead'] ?? '') === '1') {
        jeab_reminder_close_linked_lead($row);
    }
    return $row;
}

function jeab_snooze_reminder(JeabStore $store, int $id, string $by): array
{
    $map = [
        '2h' => '+2 hours',
        '2d' => '+2 days',
        '1w' => '+1 week',
    ];
    if (!isset($map[$by])) {
        throw new InvalidArgumentException('Okänd knuff.');
    }
    $row = $store->getReminder($id);
    if (!$row) {
        throw new InvalidArgumentException('Påminnelsen finns inte.');
    }
    jeab_reminder_require_actor($row);
    // Väggklocka i Stockholm. date_default_timezone kan vara UTC i en CLI-process.
    $tz = new DateTimeZone(defined('JEAB_TZ') ? JEAB_TZ : 'Europe/Stockholm');
    $now = new DateTimeImmutable('now', $tz);
    $base = $now;
    $currentDue = $row['due_at'] ?? null;
    if ($currentDue) {
        $dueDt = new DateTimeImmutable((string) $currentDue, $tz);
        if ($dueDt > $now) {
            $base = $dueDt;
        }
    }
    $dueDt = $base->modify($map[$by]);
    if ($by !== '2h') {
        $dueDt = $dueDt->setTime(0, 0);
    }
    $due = $dueDt->format('Y-m-d H:i:s');

    return jeab_update_reminder($store, $id, ['due_at' => $due]);
}

/**
 * reminder.get_due. Bara läsning. Svaret är due_at. Ingen kundtext, inget skickas.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminder_get_due(JeabStore $store, array $input): array
{
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'id krävs.', 'status' => 422, 'read_only' => true, 'dry' => 1, 'sent' => 0];
    }
    $row = jeab_get_reminder($store, $id);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'Påminnelsen finns inte.', 'status' => 404, 'read_only' => true, 'dry' => 1, 'sent' => 0];
    }

    return [
        'ok' => true,
        'action' => 'reminder.get_due',
        'read_only' => true,
        'dry' => 1,
        'sent' => 0,
        'id' => $id,
        'due_at' => (string) ($row['due_at'] ?? ''),
        'status' => 200,
    ];
}

/**
 * reminders.snooze_audit. Bara läsning.
 * Knufftiden är updated_at från senaste sparningen, och bara när den dagen är idag.
 * Öppna påminnelser där due_at ligger före den tiden, eller som sparats idag
 * och fortfarande är försenade. En knuff som landar i framtiden listas inte.
 * Svaret är id, due_at och tidpunkt. Ingen kundtext, inget skickas, inget skrivs.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_snooze_audit(JeabStore $store, array $input = []): array
{
    unset($input);
    $tz = new DateTimeZone(defined('JEAB_TZ') ? JEAB_TZ : 'Europe/Stockholm');
    $now = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
    $today = substr($now, 0, 10);
    $rows = [];
    foreach ($store->listReminders(true) as $row) {
        if (!is_array($row) || !empty($row['completed_at']) || jeab_reminder_is_trashed($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $due = trim((string) ($row['due_at'] ?? ''));
        $updated = trim((string) ($row['updated_at'] ?? ''));
        $snoozedAt = ($updated !== '' && substr($updated, 0, 10) === $today) ? $updated : '';
        if ($snoozedAt === '' || $due === '') {
            continue;
        }
        $reasons = [];
        if ($due < $snoozedAt) {
            $reasons[] = 'due_before_snooze';
        }
        $snoozedToday = substr($snoozedAt, 0, 10) === $today;
        if ($snoozedToday && $due <= $now) {
            $reasons[] = 'snoozed_today_still_overdue';
        }
        if ($reasons === []) {
            continue;
        }
        $rows[] = [
            'id' => $id,
            'due_at' => $due,
            'snoozed_at' => $snoozedAt,
            'reasons' => $reasons,
        ];
    }
    usort($rows, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));

    return [
        'ok' => true,
        'action' => 'reminders.snooze_audit',
        'read_only' => true,
        'dry' => 1,
        'sent' => 0,
        'now' => $now,
        'count' => count($rows),
        'reminders' => $rows,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminder_get_due_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_reminder_get_due($store, $input);
    $result['status'] = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));

    return $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_snooze_audit_dispatch(JeabStore $store, array $input = []): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_reminders_snooze_audit($store, $input);
    $result['status'] = 200;

    return $result;
}

function jeab_toggle_reminder_tag(JeabStore $store, int $id, string $raw): array
{
    $tag = jeab_normalize_tag($raw);
    if ($tag === null) {
        throw new InvalidArgumentException('Ogiltig tagg.');
    }
    $row = jeab_get_reminder($store, $id);
    if (!$row) {
        throw new InvalidArgumentException('Påminnelsen finns inte.');
    }
    $tags = jeab_parse_tags($row['tags'] ?? []);
    if (in_array($tag, $tags, true)) {
        $tags = array_values(array_filter($tags, static fn(string $one): bool => $one !== $tag));
    } else {
        $tags[] = $tag;
    }
    return jeab_update_reminder($store, $id, ['tags' => $tags]);
}

/**
 * Bokningen lämnar contact_id. Kundfälten följer inte med när kontakten är källan.
 *
 * @param array<string, mixed> $booking
 * @return array<string, mixed>
 */
function jeab_reminder_from_booking_details(array $booking, ?string $phone): array
{
    $contactId = trim((string) ($booking['contact_id'] ?? ''));
    $details = [
        'company' => jeab_booking_company($booking),
        'assignees' => jeab_assignees_from_row($booking),
        'note' => $booking['notes'] ?? null,
    ];
    if ($contactId !== '') {
        $details['contact_id'] = $contactId;
    }
    if ($contactId === '' || !function_exists('jeab_contacts_copies_hold') || !jeab_contacts_copies_hold()) {
        $details['customer_name'] = $booking['customer_name'] ?? null;
        $details['email'] = $booking['email'] ?? null;
        $details['phone'] = $phone;
        $details['address'] = $booking['address'] ?? null;
    }

    return $details;
}

function jeab_reminder_from_booking(JeabStore $store, array $booking): array
{
    $job = function_exists('jeab_job_types_label')
        ? jeab_job_types_label($booking)
        : null;
    if ($job === '') {
        $job = $booking['job_type'] && isset(JEAB_JOB_TYPES[$booking['job_type']])
            ? JEAB_JOB_TYPES[$booking['job_type']]
            : null;
    }
    $title = $job ?: trim((string) ($booking['customer_name'] ?? ''));
    if ($title === '') {
        $title = 'Bokning';
    }

    $existing = $store->findOpenReminderByBooking((int) $booking['id']);
    if ($existing) {
        return $existing;
    }

    try {
        $phone = jeab_normalize_reminder_phone($booking['phone'] ?? null);
    } catch (InvalidArgumentException) {
        $phone = null;
    }

    return jeab_create_reminder(
        $store,
        $title,
        $booking['starts_at'],
        null,
        (int) $booking['id'],
        jeab_reminder_from_booking_details($booking, $phone)
    );
}

function jeab_format_phone_display(string $phone): string
{
    $raw = trim($phone);
    if ($raw === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($digits, '46') && strlen($digits) === 11) {
        $digits = '0' . substr($digits, 2);
    }
    if (strlen($digits) === 10) {
        return substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 2) . ' ' . substr($digits, 8);
    }
    return $raw;
}

function jeab_reminder_card_due(array $row, bool $open = false): string
{
    if (!empty($row['completed_at'])) {
        if ($open) {
            $stamp = jeab_format_due_contact($row['due_at'] ?? $row['completed_at'] ?? null);
            return $stamp !== '' ? $stamp : jeab_format_due_short($row['completed_at'] ?? null);
        }
        $short = jeab_format_due_short($row['due_at'] ?? $row['completed_at'] ?? null);
        return $short !== '' ? $short : 'klar';
    }
    return jeab_format_due($row['due_at'] ?? null);
}

function jeab_format_created_ymd(?string $createdAt): string
{
    if (!$createdAt) {
        return '';
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    return (new DateTimeImmutable($createdAt, $tz))->format('ymd');
}

function jeab_format_due_short(?string $dueAt): string
{
    if (!$dueAt) {
        return '';
    }
    static $tz = null;
    static $tzName = '';
    $name = date_default_timezone_get();
    if (!$tz instanceof DateTimeZone || $tzName !== $name) {
        $tz = new DateTimeZone($name);
        $tzName = $name;
    }
    $dt = new DateTimeImmutable($dueAt, $tz);
    return $dt->format('j') . '/' . $dt->format('n');
}

function jeab_tel_href(string $phone): string
{
    return 'tel:' . jeab_phone_href_number($phone);
}

function jeab_sms_href(string $phone): string
{
    return 'sms:' . jeab_phone_href_number($phone);
}

function jeab_short_email(string $email, int $max = 20): string
{
    $email = trim($email);
    if ($email === '') {
        return '';
    }
    $len = function_exists('mb_strlen') ? mb_strlen($email, 'UTF-8') : strlen($email);
    if ($len <= $max) {
        return $email;
    }
    $at = strrpos($email, '@');
    if ($at === false) {
        $cut = function_exists('mb_substr') ? mb_substr($email, 0, max(1, $max - 1), 'UTF-8') : substr($email, 0, max(1, $max - 1));
        return $cut . '…';
    }
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    $keep = max(1, $max - strlen($domain) - 2);
    if (strlen($local) > $keep) {
        $slice = function_exists('mb_substr') ? mb_substr($local, 0, max(1, $keep - 1), 'UTF-8') : substr($local, 0, max(1, $keep - 1));
        $local = $slice . '…';
    }
    $out = $local . '@' . $domain;
    $outLen = function_exists('mb_strlen') ? mb_strlen($out, 'UTF-8') : strlen($out);
    if ($outLen > $max) {
        $start = function_exists('mb_substr') ? mb_substr($email, 0, 2, 'UTF-8') : substr($email, 0, 2);
        return $start . '@…';
    }
    return $out;
}

function jeab_phone_ask_html(string $text): string
{
    $phones = jeab_find_phones($text);
    if ($phones === []) {
        return '';
    }
    ob_start();
    echo '<div class="phone-asks">';
    foreach ($phones as $phone) {
        echo jeab_phone_link_html($phone);
    }
    echo '</div>';
    return ob_get_clean();
}

function jeab_phone_link_html(string $phone): string
{
    $phone = trim($phone);
    if ($phone === '') {
        return '';
    }
    ob_start();
    ?>
    <div class="contact-phone">
        <a class="contact-digits" href="<?= e(jeab_tel_href($phone)) ?>" data-js="tel"><?= e($phone) ?></a>
    </div>
    <?php
    return ob_get_clean();
}

function jeab_contact_line_html(array $row, bool $open = false): string
{
    $view = jeab_reminder_shown_customer($row);
    $phone = $view['phone'];
    $email = $view['email'];
    if ($phone === '' && $email === '' && !$open) {
        return '';
    }
    $id = (int) ($row['id'] ?? 0);
    $listAction = jeab_reminders_url(['open' => $id > 0 ? $id : null]);
    $short = $email !== '' ? jeab_short_email($email) : '';
    ob_start();
    ?>
    <div class="contact-line" x-apple-data-detectors="false">
        <div class="contact-show">
            <div class="contact-phone">
                <a class="contact-digits" href="<?= $phone !== '' ? e(jeab_tel_href($phone)) : '#' ?>" data-js="tel"<?= $phone === '' ? ' hidden' : '' ?>><?= e($phone !== '' ? jeab_format_phone_display($phone) : $phone) ?></a>
            </div>
            <button type="button" class="contact-pen" data-js="contact-edit" aria-label="Redigera kontakt"<?= $open ? '' : ' hidden' ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>
                    <path d="m15 5 4 4"/>
                </svg>
            </button>
            <span class="contact-epost"<?= $email === '' ? ' hidden' : '' ?>><span class="contact-epost-label">E-post</span> <a class="contact-mail" href="<?= $email !== '' ? e(jeab_mailto_href($email)) : '#' ?>" data-js="mail"><?= e($email) ?></a></span>
        </div>
        <form class="contact-edit-form" method="post" action="<?= e($listAction) ?>" data-js="contact-form" novalidate hidden>
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="reminder.update">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input class="contact-edit-phone" type="tel" name="phone" value="<?= e($phone) ?>" placeholder="Telefon" autocomplete="tel" inputmode="tel">
            <input class="contact-edit-email" type="email" name="email" value="<?= e($email) ?>" placeholder="E-post" autocomplete="email">
            <button type="submit" class="contact-edit-save">Spara</button>
            <p class="contact-edit-error" data-js="contact-error" role="alert" hidden></p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

function jeab_contact_icons_html(array $row): string
{
    return jeab_contact_line_html($row);
}

function jeab_reminder_due_chip(array $row, string $actionUrl): string
{
    $id = (int) ($row['id'] ?? 0);
    $due = jeab_format_due($row['due_at'] ?? null);
    $overdue = empty($row['completed_at']) && !empty($row['due_at']) && $row['due_at'] < jeab_now();
    ob_start();
    ?>
    <form class="due-form item-due<?= $overdue ? ' is-late' : '' ?>" method="post" action="<?= e($actionUrl) ?>" data-js="due">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="reminder.due">
        <input type="hidden" name="id" value="<?= $id ?>">
        <span class="due<?= $due === '' ? ' is-empty' : '' ?>"><?= e($due) ?></span>
        <button type="button" class="due-clock" aria-label="Välj tid">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="16" rx="2"/>
                <path d="M16 3v4M8 3v4M3 11h18"/>
            </svg>
        </button>
        <input class="due-input" type="date" name="due_at" value="<?= e(jeab_due_input_value($row['due_at'] ?? null)) ?>">
    </form>
    <?php
    return ob_get_clean();
}

function jeab_snooze_forms(int $id, string $actionUrl, array $row = []): string
{
    $buttons = [
        '2h' => '2h',
        '2d' => '2d',
        '1w' => '1v',
    ];
    ob_start();
    echo '<div class="snooze">';
    foreach ($buttons as $by => $label) {
        ?>
        <form method="post" action="<?= e($actionUrl) ?>" data-js="snooze">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="reminder.snooze">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="by" value="<?= e($by) ?>">
            <button type="submit"><?= e($label) ?></button>
        </form>
        <?php
    }
    if ($row !== []) {
        echo jeab_reminder_due_chip($row, $actionUrl);
    }
    echo '</div>';
    return ob_get_clean();
}

function jeab_reminder_who_current(array $row): string
{
    $names = jeab_assignees_from_row($row);
    return trim((string) ($names[0] ?? ''));
}

function jeab_reminder_who_html(array $row, string $listAction): string
{
    $company = jeab_normalize_company($row['company'] ?? null) ?? jeab_selected_company();
    $names = jeab_company_names($company);
    $otherOffice = $company === 'malmo' ? 'jarfalla' : 'malmo';
    foreach (jeab_company_names($otherOffice) as $otherName) {
        if (!in_array($otherName, $names, true)) {
            $names[] = $otherName;
        }
    }
    $current = jeab_reminder_who_current($row);
    $id = (int) ($row['id'] ?? 0);
    if ($names === []) {
        return '';
    }
    ob_start();
    $person = ($current !== '' && function_exists('jeab_person_by_name'))
        ? jeab_person_by_name($current, $company)
        : null;
    $resolved = is_array($person) ? trim((string) ($person['name'] ?? $current)) : $current;
    echo '<p class="item-who-label">Ansvarig</p>';
    echo '<div class="item-who-row" role="group" aria-label="Ansvarig">';
    foreach ($names as $name) {
        $on = $resolved !== '' && strcasecmp($resolved, $name) === 0;
        ?>
        <form class="item-who-form" method="post" action="<?= e($listAction) ?>" data-js="who">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="reminder.who">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="company" value="<?= e((string) $company) ?>">
            <input type="hidden" name="who" value="<?= e($name) ?>">
            <input type="hidden" name="assignee" value="<?= e($name) ?>">
            <button type="submit" class="item-who-chip<?= $on ? ' is-on' : '' ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= e($name) ?></button>
        </form>
        <?php
    }
    echo '</div>';
    return ob_get_clean();
}

function jeab_reminder_tag_form_html(array $row, string $listAction, string $tag, string $idPrefix = ''): string
{
    $tags = jeab_parse_tags($row['tags'] ?? []);
    $id = (int) ($row['id'] ?? 0);
    $on = in_array($tag, $tags, true);
    $need = '';
    if ($tag === 'vunnen' && function_exists('jeab_ordrar_vunnen_need_label')) {
        $need = jeab_ordrar_vunnen_need_label($row, $on);
    }
    $blocked = $need !== '';
    $refusal = '';
    if ($blocked) {
        $refusal = str_starts_with($need, 'Ordern skapades inte') ? $need : 'Ordern skapades inte. ' . $need;
    }
    $formId = $idPrefix . 'tagf-' . substr(hash('sha256', $tag), 0, 8) . '-' . $id;
    ob_start();
    ?>
    <form id="<?= e($formId) ?>" class="tag-toggle" method="post" action="<?= e($listAction) ?>"<?= $tag === 'vunnen' ? ' data-js="vunnen"' : ' data-js="tag"' ?><?php if ($blocked): ?> data-vunnen-need="<?= e($refusal) ?>"<?php endif; ?>>
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="reminder.tag">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="tag" value="<?= e($tag) ?>">
        <button type="submit" form="<?= e($formId) ?>" class="tag-chip<?= $tag === 'vunnen' ? ' is-primary' : '' ?><?= $on ? ' is-on' : '' ?>"<?php if ($blocked): ?> title="<?= e($refusal) ?>" aria-describedby="<?= e($idPrefix) ?>vunnen-need-<?= $id ?>"<?php endif; ?>>#<?= e($tag) ?></button>
        <?php if ($blocked): ?>
            <p class="vunnen-need" id="<?= e($idPrefix) ?>vunnen-need-<?= $id ?>" role="alert"><?= e($refusal) ?></p>
        <?php endif; ?>
    </form>
    <?php
    return ob_get_clean();
}

function jeab_reminder_tags_html(array $row, string $listAction, bool $hidden = false, array $skip = [], string $idPrefix = ''): string
{
    $id = (int) ($row['id'] ?? 0);
    ob_start();
    echo '<div class="tag-chips tag-pick" id="' . e($idPrefix) . 'tag-pick-' . $id . '"' . ($hidden ? ' hidden' : '') . '>';
    foreach (jeab_reminder_tag_choices() as $tag) {
        if (in_array($tag, $skip, true)) {
            continue;
        }
        echo jeab_reminder_tag_form_html($row, $listAction, $tag, $idPrefix);
    }
    echo '</div>';
    return ob_get_clean();
}

function jeab_reminder_booking_hit(array $row): array
{
    $view = jeab_reminder_shown_customer($row);
    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => $view['name'],
        'email' => $view['email'],
        'phone' => $view['phone'],
        'address' => $view['display'] !== '' ? $view['display'] : jeab_reminder_address_source($row),
        'notes' => jeab_reminder_display_note($row),
        'assignee' => jeab_reminder_who_current($row),
    ];
}

function jeab_reminder_calendar_btn_html(array $row): string
{
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_bookings_url')) {
        return '';
    }
    $href = jeab_bookings_url(['from_reminder' => $id]);
    return '<div class="item-cal-row"><a class="item-cal-btn tap44" href="' . e($href) . '" data-js="reminder-book">boka tid i kalendern</a></div>';
}

function jeab_reminder_more_html(array $row, string $listAction, string $created = '', string $due = ''): string
{
    $id = (int) ($row['id'] ?? 0);
    $panelId = 'item-more-' . $id;
    // Inkomstdatumet är .item-arrived under knapparna. Samma d/m här blev en andra
    // rad nere till höger på det öppna kortet.
    unset($created);
    ob_start();
    ?>
    <div class="item-foot">
        <div class="item-tags-head">
            <button type="button" class="item-tags-toggle tap44" data-js="toggle-tags" aria-expanded="false" aria-controls="<?= e($panelId) ?>"><span>Visa mer</span><svg class="item-tags-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
            <p class="item-created" hidden></p>
        </div>
        <div class="item-more-panel" id="<?= e($panelId) ?>" hidden>
            <?= jeab_reminder_calendar_btn_html($row) ?>
            <?= jeab_reminder_tags_html($row, $listAction, false) ?>
            <?= jeab_reminder_who_html($row, $listAction) ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Ring-knapp direkt på raden (mobil). Bara öppna påminnelser med nummer. */
function jeab_reminder_call_html(array $row): string
{
    return jeab_reminder_call_link((string) (jeab_reminder_shown_customer($row)['phone'] ?? ''), !empty($row['completed_at']));
}

function jeab_reminder_call_link(string $phone, bool $done): string
{
    if ($phone === '' || $done) {
        return '';
    }
    return '<a class="item-call" href="' . e(jeab_tel_href($phone)) . '" data-js="tel" aria-label="Ring ' . e(jeab_format_phone_display($phone)) . '">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg></a>';
}

/**
 * Produktkod från rubrik och taggar. Samma ordning som jeab_inkorg_reminder_offer.
 * Påminnelser laddar inte inkorg_auto, så träffen räknas här.
 */
function jeab_reminder_desk_offer(array $row): string
{
    if (function_exists('jeab_inkorg_reminder_offer')) {
        return jeab_inkorg_reminder_offer($row);
    }
    $direct = trim((string) ($row['product'] ?? ''));
    if ($direct !== '') {
        $code = jeab_reminder_product_code($direct);
        if (in_array($code, ['laddbox', 'batteri', 'sol', 'sol+batteri'], true)) {
            return $code;
        }
    }
    $tags = $row['tags'] ?? '';
    if (is_array($tags)) {
        $tags = implode(' ', array_map('strval', $tags));
    }
    $hay = mb_strtolower(trim((string) ($row['title'] ?? '') . ' ' . (string) $tags));
    $flat = str_replace([' ', '_', '-'], '', $hay);
    if (str_contains($flat, 'sol+batteri') || preg_match('/sol(?:celler)?\s*(?:\+|och|plus)\s*batter/u', $hay) === 1) {
        return 'sol+batteri';
    }
    if (preg_match('/solcell/u', $hay) === 1) {
        return 'sol';
    }
    if (preg_match('/batter/u', $hay) === 1) {
        return 'batteri';
    }
    if (preg_match('/elbil|laddbox|laddare/u', $hay) === 1) {
        return 'laddbox';
    }

    return '';
}

/** Skrivbordets listrad, samma uppställning som inkorgen. Dold under 900px. */
function jeab_reminder_desk_badge(array $row): string
{
    $offer = jeab_reminder_desk_offer($row);
    if ($offer === 'sol+batteri') {
        return 'Sol+batteri';
    }
    if (function_exists('jeab_inkorg_list_offer_label')) {
        return jeab_inkorg_list_offer_label($offer);
    }

    return '';
}

function jeab_reminder_desk_face_html(array $row): string
{
    $view = function_exists('jeab_reminder_shown_customer') ? jeab_reminder_shown_customer($row) : [];
    $name = jeab_reminder_trim_sep(trim((string) ($view['name'] ?? '')));
    $street = jeab_reminder_trim_sep(trim((string) ($view['street'] ?? '')));
    $city = jeab_reminder_trim_sep(trim((string) ($view['city'] ?? '')));
    $place = function_exists('jeab_inkorg_fast_place_line')
        ? jeab_inkorg_fast_place_line($street, $city)
        : trim($street . ($street !== '' && $city !== '' ? ', ' : '') . $city);
    $subject = trim((string) ($row['title'] ?? ''));
    $preview = trim((string) preg_replace('/\s+/u', ' ', strip_tags(jeab_reminder_notes_text($row))));
    if (mb_strlen($preview) > 280) {
        $preview = rtrim(mb_substr($preview, 0, 280));
    }
    $badge = jeab_reminder_desk_badge($row);
    $done = !empty($row['completed_at']);
    $when = $done && function_exists('jeab_reminder_card_due')
        ? jeab_reminder_card_due($row, false)
        : (function_exists('jeab_format_due_short') ? jeab_format_due_short($row['due_at'] ?? null) : '');
    $html = '<div class="pam-ix">';
    $html .= '<div class="pam-ix-l1"><span class="pam-ix-nm">' . e($name !== '' ? $name : '(okänd)') . '</span>';
    if ($when !== '') {
        $html .= '<span class="pam-ix-dt">' . e($when) . '</span>';
    }
    $html .= '</div>';
    if ($place !== '') {
        $html .= '<div class="pam-ix-addr">' . e($place) . '</div>';
    }
    if ($badge !== '' || $subject !== '') {
        $html .= '<div class="pam-ix-l2">';
        if ($badge !== '') {
            $html .= '<span class="pam-ix-prod">' . e($badge) . '</span>';
        }
        if ($subject !== '') {
            $html .= '<span class="pam-ix-sub">' . e($subject) . '</span>';
        }
        $html .= '</div>';
    }
    if ($preview !== '') {
        $html .= '<div class="pam-ix-pre">' . e($preview) . '</div>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * När ärendet kom in.
 *
 * Kopplat inkorgsärende: tidigaste received_at som inte är en intern anteckning.
 * Tråden är reminders.thread_id, annars id i anteckningens inkorgslänk.
 * Saknas ärende eller tidsstämpel används påminnelsens created_at, då kortet
 * självt kom in. Visas som d/m utan nollor, till exempel 3/10.
 */
function jeab_reminder_case_thread_id(array $row): string
{
    $tid = trim((string) ($row['thread_id'] ?? ''));
    if ($tid === '') {
        $tid = jeab_reminder_thread_from_text(jeab_reminder_notes_text($row));
    }
    if ($tid === '' || preg_match('/^[A-Za-z0-9_%.\-]+$/', $tid) !== 1) {
        return '';
    }

    return $tid;
}

/** @param list<array<string, mixed>> $rows */
function jeab_reminder_arrived_preload(array $rows): void
{
    $want = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = jeab_reminder_case_thread_id($row);
        if ($tid === '' || !empty($GLOBALS['_JEAB_REMINDER_ARRIVED_READY'][$tid])) {
            continue;
        }
        $want[$tid] = true;
    }
    if ($want === []) {
        return;
    }
    $found = jeab_reminder_arrived_lookup(array_keys($want));
    foreach (array_keys($want) as $tid) {
        $GLOBALS['_JEAB_REMINDER_ARRIVED'][$tid] = (string) ($found[$tid] ?? '');
        $GLOBALS['_JEAB_REMINDER_ARRIVED_READY'][$tid] = true;
    }
}

/**
 * @param list<string> $tids
 * @return array<string, string>
 */
function jeab_reminder_arrived_lookup(array $tids): array
{
    $tids = array_values(array_unique(array_filter(
        $tids,
        static fn ($tid): bool => is_string($tid) && $tid !== ''
    )));
    if ($tids === [] || getenv('JEAB_DISABLE_SQLITE') === '1' || !function_exists('jeab_store')) {
        return [];
    }
    try {
        $store = jeab_store();
        if (!$store instanceof JeabPdoStore) {
            return [];
        }
        $pdo = $store->pdo();
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach (array_chunk($tids, 80) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $sql = 'SELECT thread_id, MIN(received_at) AS arrived FROM inkorg_messages'
            . ' WHERE thread_id IN (' . $marks . ')'
            . ' AND (is_note = 0 OR is_note IS NULL)'
            . " AND received_at IS NOT NULL AND received_at <> ''"
            . ' GROUP BY thread_id';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($chunk);
            $hits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
        foreach (is_array($hits) ? $hits : [] as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $tid = (string) ($hit['thread_id'] ?? '');
            $at = trim((string) ($hit['arrived'] ?? ''));
            if ($tid !== '' && $at !== '') {
                $out[$tid] = $at;
            }
        }
    }

    return $out;
}

function jeab_reminder_arrived_at(array $row): string
{
    $tid = jeab_reminder_case_thread_id($row);
    if ($tid !== '') {
        if (empty($GLOBALS['_JEAB_REMINDER_ARRIVED_READY'][$tid])) {
            jeab_reminder_arrived_preload([$row]);
        }
        $at = trim((string) ($GLOBALS['_JEAB_REMINDER_ARRIVED'][$tid] ?? ''));
        if ($at !== '') {
            return $at;
        }
    }

    return trim((string) ($row['created_at'] ?? ''));
}

function jeab_reminder_arrived_day(array $row): string
{
    $at = jeab_reminder_arrived_at($row);
    if ($at === '') {
        return '';
    }
    try {
        return jeab_format_created_day($at);
    } catch (Throwable) {
        return '';
    }
}

function jeab_reminder_arrived_html(array $row): string
{
    $day = jeab_reminder_arrived_day($row);
    if ($day === '') {
        return '';
    }

    return '<p class="item-arrived"><span class="item-arrived-label" aria-label="Inkom ' . e($day) . '">' . e($day) . '</span></p>';
}

/**
 * Stängt listkort: adress, namn, förfallo och bocken. Redigeraren, knuff,
 * taggar och ansvarig byggs bara när kortet är öppet.
 */
function jeab_reminder_row_closed(array $row): string
{
    $id = (int) ($row['id'] ?? 0);
    $open = false;
    $done = !empty($row['completed_at']);
    $flagged = !empty($row['flagged']);
    $dueRaw = (string) ($row['due_at'] ?? '');
    $dueShutLabel = $done
        ? jeab_reminder_card_due($row, false)
        : jeab_format_due_short($row['due_at'] ?? null);
    $dueShutFull = jeab_reminder_due_with_clock($dueShutLabel, $dueRaw);
    $dueShutClock = '';
    if ($dueShutFull !== $dueShutLabel && preg_match('/ (\d{2}:\d{2})$/', $dueShutFull, $dueShutClockMatch) === 1) {
        $dueShutClock = $dueShutClockMatch[1];
    }
    $view = jeab_reminder_shown_customer($row);
    $name = jeab_reminder_trim_sep((string) ($view['name'] ?? ''));
    $title = trim((string) ($row['title'] ?? ''));
    $street = (string) ($view['street'] ?? '');
    $city = (string) ($view['city'] ?? '');
    $inputAddress = (string) ($view['display'] ?? '');
    $shutAddr = $street !== '' ? $street : ($inputAddress !== '' ? $inputAddress : $title);
    if (function_exists('jeab_name_address_card_lines')) {
        $lines = jeab_name_address_card_lines($street, $inputAddress, $title);
        $street = (string) ($lines['street'] ?? $street);
        $shutAddr = (string) ($lines['shut'] ?? $shutAddr);
    }
    $street = jeab_reminder_trim_sep($street);
    $city = jeab_reminder_trim_sep($city);
    $shutAddr = jeab_reminder_trim_sep($shutAddr);
    $noteSource = jeab_reminder_notes_text($row);
    if (function_exists('jeab_name_address_note_header')) {
        $noteSource = jeab_name_address_note_header($noteSource, $name);
    }
    $noteHtml = jeab_reminder_note_html($noteSource);
    $contactLink = jeab_reminder_contact_link_html($row);
    $inkorgLink = jeab_reminder_inkorg_link_html($row);
    $tapTotal = jeab_reminder_tap_total($row);
    $tapMailLabel = jeab_reminder_tap_total_label($row);
    $late = jeab_reminder_is_late($row);
    $listAction = jeab_reminders_url(['open' => $id]);
    $stamp = jeab_reminder_row_stamp($row);
    $checkSvg = '<svg class="check-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';

    ob_start();
    ?>
    <li class="item<?= $done ? ' is-done' : '' ?><?= $late ? ' is-late' : '' ?><?= $flagged ? ' is-flagged' : '' ?>" id="r-<?= $id ?>" data-id="<?= $id ?>" data-sync-stamp="<?= e($stamp) ?>"<?= !empty($row['_jeab_open_extra']) ? ' data-open-extra="1"' : '' ?> data-due-at="<?= e($dueRaw) ?>" data-completed-at="<?= e((string) ($row['completed_at'] ?? '')) ?>">
        <form class="check-form" method="post" action="<?= e($listAction) ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="reminder.toggle">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="check" type="submit" aria-label="<?= $done ? 'Klar' : 'Markera som klar' ?>"><?= $checkSvg ?></button>
        </form>
        <div class="item-stack">
            <div class="item-headrow item-head-shut">
                <a class="item-main" href="<?= e($listAction) ?>">
                    <div class="item-shut-top">
                        <div class="item-shut-copy">
                            <p class="item-shut-addr"><?= e($shutAddr) ?><?php if ($city !== '' && $shutAddr !== ''): ?><span class="item-shut-city">, <?= e($city) ?></span><?php elseif ($city !== ''): ?><?= e($city) ?><?php endif; ?></p>
                            <?php if ($name !== ''): ?><p class="item-shut-name"><?= e($name) ?></p><?php endif; ?>
                        </div>
                    </div>
                    <?= jeab_reminder_desk_face_html($row) ?>
                    <span class="visually-hidden"><?= jeab_reminder_title_html_from_view($row, $view, $tapTotal) ?><?php if (!$open && trim((string) ($view['email'] ?? '')) !== ''): ?> <?= e((string) $view['email']) ?><?php endif; ?></span>
                </a>
                <div class="item-shut-meta">
                    <?= jeab_reminder_test_chip_html($row) ?>
                    <?php if ($dueShutFull !== ''): ?><span class="item-due-chip"><?= e($dueShutClock !== '' ? $dueShutLabel : $dueShutFull) ?><?php if ($dueShutClock !== ''): ?><span class="item-due-clock"> <?= e($dueShutClock) ?></span><?php endif; ?></span><?php endif; ?>
                    <?php if ($tapTotal > 0): ?><span class="item-tap-total" title="<?= e($tapMailLabel) ?>" aria-label="<?= e($tapMailLabel) ?>"><?= (int) $tapTotal ?></span><?php endif; ?>
                    <?= jeab_reminder_call_link((string) ($view['phone'] ?? ''), $done) ?>
                </div>
            </div>
            <?php if ($noteHtml !== ''): ?>
                <div class="item-note"><?= $noteHtml ?></div>
            <?php endif; ?>
            <?php if ($contactLink !== '' || $inkorgLink !== ''): ?>
                <div class="item-link-row"><?= $contactLink ?><?php if ($inkorgLink !== ''): ?><div class="item-inkorg-row"><?= $inkorgLink ?></div><?php endif; ?></div>
            <?php endif; ?>
            <?= jeab_reminder_arrived_html($row) ?>
        </div>
    </li>
    <?php
    return (string) ob_get_clean();
}

function jeab_reminder_row(array $row, ?bool $open = null): string
{
    $id = (int) ($row['id'] ?? 0);
    $open = $open ?? (jeab_reminder_open_id() === $id);
    if (!$open) {
        return jeab_reminder_row_closed($row);
    }
    $done = !empty($row['completed_at']);
    $flagged = !empty($row['flagged']);
    $due = jeab_format_due_contact($row['due_at'] ?? null);
    $dueRaw = (string) ($row['due_at'] ?? '');
    $dueChip = jeab_reminder_due_with_clock(jeab_reminder_card_due($row, $open), $dueRaw);
    // Listkortet (stängt): kort datum «14/10». Klockslaget ligger i en egen span
    // så mobilen kan dölja det. Datorn visar datum och tid som förut.
    $dueShutLabel = !empty($row['completed_at'])
        ? jeab_reminder_card_due($row, false)
        : jeab_format_due_short($row['due_at'] ?? null);
    $dueShutFull = jeab_reminder_due_with_clock($dueShutLabel, $dueRaw);
    $dueShutClock = '';
    if ($dueShutFull !== $dueShutLabel && preg_match('/ (\d{2}:\d{2})$/', $dueShutFull, $dueShutClockMatch) === 1) {
        $dueShutClock = $dueShutClockMatch[1];
    }
    $notes = jeab_reminder_view_note(jeab_reminder_display_note($row));
    $inkorgLink = jeab_reminder_inkorg_link_html($row);
    $view = jeab_reminder_shown_customer($row);
    $name = $view['name'];
    $title = trim((string) ($row['title'] ?? ''));
    $street = $view['street'];
    $city = $view['city'];
    $inputAddress = $view['display'];
    $shutAddr = $street !== '' ? $street : ($inputAddress !== '' ? $inputAddress : $title);
    if (function_exists('jeab_name_address_card_lines')) {
        $lines = jeab_name_address_card_lines($street, $inputAddress, $title);
        $street = $lines['street'];
        $inputAddress = $lines['input'];
        $shutAddr = $lines['shut'];
    } elseif ($inputAddress === '') {
        $inputAddress = $title;
    }
    $noteSource = jeab_reminder_notes_text($row);
    if (function_exists('jeab_name_address_note_header')) {
        $noteSource = jeab_name_address_note_header($noteSource, $name);
    }
    $noteHtml = jeab_reminder_note_html($noteSource);
    $contactLink = jeab_reminder_contact_link_html($row);
    $tapTotal = jeab_reminder_tap_total($row);
    $tapMailLabel = jeab_reminder_tap_total_label($row);
    $street = jeab_reminder_trim_sep($street);
    $city = jeab_reminder_trim_sep($city);
    $name = jeab_reminder_trim_sep($name);
    $late = jeab_reminder_is_late($row);
    $created = jeab_format_created_day($row['created_at'] ?? null);
    $listAction = jeab_reminders_url(['open' => $id]);
    $checkSvg = '<svg class="check-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';

    ob_start();
    ?>
    <li class="item<?= $done ? ' is-done' : '' ?><?= $late ? ' is-late' : '' ?><?= $flagged ? ' is-flagged' : '' ?><?= $open ? ' is-open' : '' ?>" id="r-<?= $id ?>" data-id="<?= $id ?>" data-sync-stamp="<?= e(jeab_reminder_row_stamp($row)) ?>"<?= !empty($row['_jeab_open_extra']) ? ' data-open-extra="1"' : '' ?> data-due-at="<?= e((string) ($row['due_at'] ?? '')) ?>" data-completed-at="<?= e((string) ($row['completed_at'] ?? '')) ?>">
        <form class="check-form" method="post" action="<?= e($listAction) ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="reminder.toggle">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="check" type="submit" aria-label="<?= $done ? 'Klar' : 'Markera som klar' ?>"><?= $checkSvg ?></button>
        </form>
        <div class="item-stack">
            <div class="item-headrow item-head-open"<?= $open ? '' : ' hidden' ?>>
                <form class="item-inline item-main" method="post" action="<?= e($listAction) ?>" data-js="inline">
                    <?= jeab_csrf_field() ?>
                    <input type="hidden" name="action" value="reminder.update">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="item-addr-row">
                        <textarea class="item-title box-field" name="address" rows="1" placeholder="Adress" autocomplete="street-address" data-js="grow-note"><?= e($inputAddress) ?></textarea>
                        <?php if ($dueChip !== ''): ?>
                            <button type="button" class="item-due-chip" data-js="collapse-row"><?= e($dueChip) ?></button>
                        <?php endif; ?>
                        <?php if ($due !== ''): ?><span class="item-due-label"><?= e($due) ?></span><?php endif; ?>
                    </div>
                    <div class="item-name-row">
                        <?= jeab_reminder_test_chip_html($row) ?>
                        <input class="item-title box-field" type="text" name="customer" value="<?= e($name) ?>" placeholder="Namn" autocomplete="name">
                        <?php if ($tapTotal > 0): ?>
                            <span class="item-tap-total" title="<?= e($tapMailLabel) ?>" aria-label="<?= e($tapMailLabel) ?>"><?= (int) $tapTotal ?></span>
                        <?php endif; ?>
                    </div>
                    <textarea class="item-note box-field" name="notes" rows="2" placeholder="Anteckning" data-js="grow-note"><?= e($notes) ?></textarea>
                </form>
            </div>
            <div class="item-headrow item-head-shut"<?= $open ? ' hidden' : '' ?>>
                <a class="item-main" href="<?= e(jeab_reminders_url(['open' => $id])) ?>">
                    <div class="item-shut-top">
                        <div class="item-shut-copy">
                            <p class="item-shut-addr"><?= e($shutAddr) ?><?php if ($city !== '' && $shutAddr !== ''): ?><span class="item-shut-city">, <?= e($city) ?></span><?php elseif ($city !== ''): ?><?= e($city) ?><?php endif; ?></p>
                            <?php if ($name !== ''): ?><p class="item-shut-name"><?= e($name) ?></p><?php endif; ?>
                        </div>
                    </div>
                    <?= jeab_reminder_desk_face_html($row) ?>
                    <span class="visually-hidden"><?= jeab_reminder_title_html_from_view($row, $view, $tapTotal) ?><?php if (!$open && trim((string) ($view['email'] ?? '')) !== ''): ?> <?= e((string) $view['email']) ?><?php endif; ?></span>
                </a>
                <div class="item-shut-meta">
                    <?= jeab_reminder_test_chip_html($row) ?>
                    <?php if ($dueShutFull !== ''): ?><span class="item-due-chip"><?= e($dueShutClock !== '' ? $dueShutLabel : $dueShutFull) ?><?php if ($dueShutClock !== ''): ?><span class="item-due-clock"> <?= e($dueShutClock) ?></span><?php endif; ?></span><?php endif; ?>
                    <?php if ($tapTotal > 0): ?><span class="item-tap-total" title="<?= e($tapMailLabel) ?>" aria-label="<?= e($tapMailLabel) ?>"><?= (int) $tapTotal ?></span><?php endif; ?>
                    <?= jeab_reminder_call_link((string) ($view['phone'] ?? ''), $done) ?>
                </div>
            </div>
            <?php if (!$open && $noteHtml !== ''): ?>
                <div class="item-note"><?= $noteHtml ?></div>
            <?php endif; ?>
            <?php if ($contactLink !== '' || $inkorgLink !== ''): ?>
                <div class="item-link-row"><?= $contactLink ?><?php if ($inkorgLink !== ''): ?><div class="item-inkorg-row"><?= $inkorgLink ?></div><?php endif; ?></div>
            <?php endif; ?>
            <?= jeab_contact_line_html($row, $open) ?>
            <div class="item-extra"<?= $open ? '' : ' hidden' ?>>
                <?php if (!$done): /* Klara: inga kontakt- eller knuffknappar */ ?>
                    <?= jeab_reminder_taps_html($row, $listAction) ?>
                    <?= jeab_snooze_forms($id, $listAction, $row) ?>
                <?php endif; ?>
                <?= jeab_reminder_more_html($row, $listAction, $created, $due) ?>
            </div>
            <?= jeab_reminder_arrived_html($row) ?>
        </div>
    </li>
    <?php
    return ob_get_clean();
}

require_once __DIR__ . '/reminders_restore.php';
require_once __DIR__ . '/reminders_dedupe.php';
