<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/contacts_sole_audit.php';
require_once __DIR__ . '/inkorg_contact_create.php';
require_once __DIR__ . '/contacts_strict.php';
require_once __DIR__ . '/lead_source.php';
require_once __DIR__ . '/inkorg_contacts.php';
require_once __DIR__ . '/inkorg_helpscout_import.php';

/**
 * contacts.link_by_email. dry=1 är standard.
 *
 * Sätter contact_id på rader som saknar den i inkorg_list och inkorg_threads.
 * Ärenden som data.inkorg_contact_create räknar som lead (Ny lead eller
 * lead_customer, med e-post eller telefon, inte raderade) redovisas som
 * inkorg_cases. Skrivningen går ändå till listan eller tråden.
 *
 * Länken sätts bara när radens normaliserade e-post är exakt lika med en
 * e-post hos exakt en kontakt i samma bolag. E-postkällan är samma som
 * data.inkorg_contact_create använder för dup_existing_email
 * (jeab_inkorg_contact_create_email_directory): SQL, JSON-ägare och
 * contact_emails. Inget skapas, inget slås ihop och inget annat fält skrivs.
 * Skickade offerter, fakturor och mejlkopior rörs inte.
 *
 * Rader utan contact_id är samma mängd som contacts.sole_audit räknar.
 * Saknas bolag på tråden hämtas det från listradens company-kolumn, samma
 * ställe som inkorgens lista sparar bolaget sidan visar, eller från mejlboxen
 * när den pekar på ett bolag. Annars no_company, och raden länkas inte.
 * contact_id skrivs där sidan läser den: trådens row_json (threadsMap) och,
 * när samma id finns i listan utan länk, listans contact_id-kolumn och row_json.
 *
 * Osäkra rader lämnas. Orsak: ambiguous_email, no_email, no_match eller no_company.
 * Svaret är per bolag och tabell, med antal och id. Ingen e-post eller namn.
 * dry=0 skriver data/.private/contacts-link-by-email/<undo_id>.json
 * (jeab_data_dir() är den privata katalogen).
 * contacts.link_by_email_undo är torr som standard och tar bort länken
 * bara på rader från den körningen som fortfarande är oförändrade.
 */

/** @return list<string> */
function jeab_contacts_link_by_email_companies(): array
{
    return ['jarfalla', 'malmo'];
}

/** @return list<string> */
function jeab_contacts_link_by_email_tables(): array
{
    return ['inkorg_list', 'inkorg_threads', 'inkorg_cases'];
}

/** @return list<string> */
function jeab_contacts_link_by_email_reasons(): array
{
    return ['ambiguous_email', 'no_email', 'no_match', 'no_company', 'from_internal', 'from_platform', 'from_blocked', 'from_unchecked'];
}

function jeab_contacts_link_by_email_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_contacts_link_by_email_ident(string $name): string
{
    if (preg_match('/^[a-z_]+$/', $name) !== 1) {
        throw new RuntimeException('Ogiltigt namn.');
    }

    return $name;
}

function jeab_contacts_link_by_email_email(string $raw): string
{
    return jeab_inkorg_contact_create_email_key($raw);
}

function jeab_contacts_link_by_email_company(string $raw): string
{
    if (function_exists('jeab_normalize_company')) {
        return (string) (jeab_normalize_company($raw) ?? '');
    }
    $fold = function_exists('mb_strtolower') ? mb_strtolower(trim($raw), 'UTF-8') : strtolower(trim($raw));
    $fold = strtr($fold, ['ä' => 'a', 'å' => 'a', 'ö' => 'o']);
    if (in_array($fold, ['jarfalla', 'jeab'], true)) {
        return 'jarfalla';
    }
    if (in_array($fold, ['malmo', 'meab'], true)) {
        return 'malmo';
    }

    return '';
}

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function jeab_contacts_link_by_email_canon(array $node): array
{
    if ($node === []) {
        return [];
    }
    $list = array_is_list($node);
    if (!$list) {
        ksort($node);
    }
    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $node[$key] = jeab_contacts_link_by_email_canon($value);
        }
    }

    return $node;
}

/** @param array<string, mixed>|null $json */
function jeab_contacts_link_by_email_fp(?array $json): string
{
    if (!is_array($json)) {
        return '';
    }
    unset($json['contact_id']);
    $encoded = json_encode(jeab_contacts_link_by_email_canon($json), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return hash('sha256', is_string($encoded) ? $encoded : '');
}

/**
 * Samma leadregel som data.inkorg_contact_create.
 *
 * @param array<string, mixed>|null $json
 */
function jeab_contacts_link_by_email_is_case(?array $json): bool
{
    if (!is_array($json)) {
        return false;
    }
    if (!empty($json['deleted']) || trim((string) ($json['deleted_at'] ?? '')) !== '') {
        return false;
    }
    $lead = is_array($json['lead_customer'] ?? null) ? $json['lead_customer'] : [];
    $email = trim((string) ($lead['email'] ?? ''));
    if ($email === '') {
        $email = trim((string) ($json['customer_email'] ?? ''));
    }
    $phone = trim((string) ($lead['phone'] ?? ''));
    if ($email === '' && $phone === '') {
        return false;
    }
    $first = is_array($json['first'] ?? null) ? $json['first'] : [];
    $labels = is_array($first['labels'] ?? null) ? $first['labels'] : [];
    if ($labels === [] && is_array($json['labels'] ?? null)) {
        $labels = $json['labels'];
    }
    $type = (string) ($labels['type'] ?? $json['list_type'] ?? '');
    if ($type === 'Ny lead') {
        return true;
    }

    return is_array($json['lead_customer'] ?? null);
}

/**
 * En e-post per rad. Leadens e-post går före kolumnen, samma ordning som
 * data.inkorg_contact_create.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 */
/**
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 * @return array{email:string,field:string,skip?:string}
 */
function jeab_contacts_link_by_email_row_email_hit(array $row, ?array $json, array $cols): array
{
    $lead = is_array($json) && is_array($json['lead_customer'] ?? null) ? $json['lead_customer'] : [];
    $checks = [
        [(string) ($lead['email'] ?? ''), 'row_json.lead_customer.email'],
    ];
    if (isset($cols['customer_email'])) {
        $checks[] = [(string) ($row['customer_email'] ?? ''), 'customer_email'];
    }
    if (is_array($json)) {
        $checks[] = [(string) ($json['customer_email'] ?? ''), 'row_json.customer_email'];
        $checks[] = [(string) ($json['email'] ?? ''), 'row_json.email'];
    }
    foreach ($checks as $check) {
        $email = jeab_contacts_link_by_email_email($check[0]);
        if ($email !== '') {
            return ['email' => $email, 'field' => $check[1]];
        }
    }
    $from = jeab_contacts_link_by_email_first_from($json);
    if ($from['email'] !== '' && $from['skip'] === '') {
        return ['email' => $from['email'], 'field' => 'row_json.first.from'];
    }

    return ['email' => '', 'field' => '', 'skip' => $from['skip']];
}

/**
 * Tjänsteavsändare som aldrig är kunden: Help Scout och Wix butiksorder.
 * Ligger här och inte i jeab_lead_sources, där butiksorder medvetet inte räknas som lead.
 */
function jeab_contacts_link_by_email_from_service(string $email): bool
{
    $email = strtolower(trim($email));
    if (in_array($email, ['orders@wix.com'], true)) {
        return true;
    }
    $dom = (string) substr(strrchr($email, '@') ?: '', 1);
    foreach (['helpscout.net', 'stores-emails.com'] as $one) {
        if ($dom === $one || str_ends_with($dom, '.' . $one)) {
            return true;
        }
    }

    return false;
}

/**
 * Första inkommande avsändaren, för vanliga mejltrådar utan leadkund och utan customer_email.
 * Egen domän, leadplattform/leadsäljare och blockerade avsändare används aldrig som nyckel.
 * Adressen används bara för att hitta contact_id. Den skrivs inte till tråden och blir aldrig mottagare.
 *
 * @param array<string, mixed>|null $json
 * @return array{email:string,skip:string}
 */
function jeab_contacts_link_by_email_first_from(?array $json): array
{
    $first = is_array($json) && is_array($json['first'] ?? null) ? $json['first'] : [];
    $email = jeab_contacts_link_by_email_email((string) ($first['from'] ?? ''));
    if ($email === '') {
        return ['email' => '', 'skip' => ''];
    }
    foreach (['jeab_contacts_inbox_email_is_own', 'jeab_lead_source_id', 'jeab_inkorg_address_blocked', 'jeab_hs_import_noreply_text'] as $guard) {
        if (!function_exists($guard)) {
            return ['email' => '', 'skip' => 'from_unchecked'];
        }
    }
    if (jeab_contacts_inbox_email_is_own($email)) {
        return ['email' => $email, 'skip' => 'from_internal'];
    }
    if (jeab_lead_source_id($email) !== '' || jeab_contacts_link_by_email_from_service($email)) {
        return ['email' => $email, 'skip' => 'from_platform'];
    }
    if (jeab_inkorg_address_blocked($email) || jeab_hs_import_noreply_text($email)) {
        return ['email' => $email, 'skip' => 'from_blocked'];
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['email' => '', 'skip' => ''];
    }

    return ['email' => $email, 'skip' => ''];
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 */
function jeab_contacts_link_by_email_row_email(array $row, ?array $json, array $cols): string
{
    return jeab_contacts_link_by_email_row_email_hit($row, $json, $cols)['email'];
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 */
/**
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 * @return array{company:string,field:string}
 */
function jeab_contacts_link_by_email_row_company_hit(array $row, ?array $json, array $cols): array
{
    if (isset($cols['company'])) {
        $fromCol = jeab_contacts_link_by_email_company((string) ($row['company'] ?? ''));
        if ($fromCol !== '') {
            return ['company' => $fromCol, 'field' => 'company'];
        }
    }
    if (is_array($json)) {
        foreach (['company', 'brand'] as $key) {
            $company = jeab_contacts_link_by_email_company((string) ($json[$key] ?? ''));
            if ($company !== '') {
                return ['company' => $company, 'field' => 'row_json.' . $key];
            }
        }
    }

    return ['company' => '', 'field' => ''];
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 */
function jeab_contacts_link_by_email_row_company(array $row, ?array $json, array $cols): string
{
    return jeab_contacts_link_by_email_row_company_hit($row, $json, $cols)['company'];
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 */
function jeab_contacts_link_by_email_has_link(array $row, ?array $json, array $cols): bool
{
    if (isset($cols['contact_id']) && trim((string) ($row['contact_id'] ?? '')) !== '') {
        return true;
    }

    return is_array($json) && trim((string) ($json['contact_id'] ?? '')) !== '';
}

/**
 * @return array<string, array<string, array<string, true>>>
 */
function jeab_contacts_link_by_email_index(PDO $pdo): array
{
    $index = [];
    foreach (jeab_inkorg_contact_create_email_directory($pdo) as $id => $info) {
        if (!is_array($info)) {
            continue;
        }
        $company = (string) ($info['company'] ?? '');
        if ($company === '' || trim((string) $id) === '') {
            continue;
        }
        $emails = is_array($info['emails'] ?? null) ? $info['emails'] : [];
        foreach ($emails as $email => $yes) {
            if ($yes && is_string($email) && $email !== '') {
                $index[$company][$email][(string) $id] = true;
            }
        }
    }

    return $index;
}

/**
 * @return array{would_link:array{count:int,ids:list<array{id:string,contact_id:string}>},remaining:array<string,array{count:int,ids:list<string>}>}
 */
function jeab_contacts_link_by_email_blank_table(): array
{
    $remaining = [];
    foreach (jeab_contacts_link_by_email_reasons() as $reason) {
        $remaining[$reason] = ['count' => 0, 'ids' => []];
    }

    return [
        'would_link' => ['count' => 0, 'ids' => []],
        'remaining' => $remaining,
    ];
}

/**
 * @return array<string, array<string, array<string, mixed>>>
 */
function jeab_contacts_link_by_email_blank_companies(): array
{
    $out = [];
    foreach (array_merge(jeab_contacts_link_by_email_companies(), ['none']) as $company) {
        $out[$company] = [];
        foreach (jeab_contacts_link_by_email_tables() as $table) {
            $out[$company][$table] = jeab_contacts_link_by_email_blank_table();
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $json
 */
function jeab_contacts_link_by_email_encode(array $json): ?string
{
    $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : null;
}

/**
 * @return list<string>
 */
function jeab_contacts_link_by_email_reports(string $physical, bool $isCase): array
{
    $reports = [$physical];
    if ($isCase) {
        $reports[] = 'inkorg_cases';
    }

    return $reports;
}

/**
 * @param array<string, array<string, mixed>> $companies
 * @param list<string> $reports
 */
function jeab_contacts_link_by_email_remain_all(array &$companies, string $company, array $reports, string $reason, string $id): void
{
    foreach ($reports as $report) {
        jeab_contacts_link_by_email_remain($companies, $company, $report, $reason, $id);
    }
}

/**
 * Bolag från raden. Saknas det: mejlbox, källa eller listrad när de pekar på ett bolag.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 * @param array{list:array<string, array<string, true>>,messages:array<string, array<string, true>>,boxes:array<string, string>} $context
 */
function jeab_contacts_link_by_email_resolved_company(string $id, array $row, ?array $json, array $cols, array $context): string
{
    $direct = jeab_contacts_link_by_email_row_company($row, $json, $cols);
    if ($direct !== '') {
        return $direct;
    }
    if (!function_exists('jeab_contacts_link_backfill_thread_co')) {
        require_once __DIR__ . '/contacts_link_backfill.php';
    }
    $derived = jeab_contacts_link_backfill_thread_co($id, $json, $context);

    return (string) ($derived['co'] ?? '');
}

/**
 * @param array<string, array<string, array<string, true>>> $index
 * @param array<string, array<string, mixed>> $companies
 * @param list<array<string, mixed>> $writes
 * @param array<string, mixed> $row
 * @param array<string, true> $cols
 * @param array{list:array<string, array<string, true>>,messages:array<string, array<string, true>>,boxes:array<string, string>} $context
 */
function jeab_contacts_link_by_email_consider(
    string $physical,
    array $row,
    array $cols,
    array $index,
    array &$companies,
    array &$writes,
    array $context
): void {
    $id = trim((string) ($row['id'] ?? ''));
    if ($id === '' || str_contains($id, '@') || preg_match('/\s/u', $id) === 1) {
        return;
    }
    $json = null;
    $rawJson = null;
    if (isset($cols['row_json']) && is_string($row['row_json'] ?? null)) {
        $rawJson = (string) $row['row_json'];
        if ($rawJson !== '') {
            $decoded = json_decode($rawJson, true);
            $json = is_array($decoded) ? $decoded : null;
        }
    }
    if (jeab_contacts_link_by_email_has_link($row, $json, $cols)) {
        return;
    }
    $caseJson = $json;
    if (is_array($caseJson) && isset($cols['customer_email'])) {
        $columnEmail = trim((string) ($row['customer_email'] ?? ''));
        if ($columnEmail !== '' && trim((string) ($caseJson['customer_email'] ?? '')) === '') {
            $caseJson['customer_email'] = $columnEmail;
        }
    }
    $isCase = jeab_contacts_link_by_email_is_case($caseJson);
    $reports = jeab_contacts_link_by_email_reports($physical, $isCase);
    $company = jeab_contacts_link_by_email_resolved_company($id, $row, $json, $cols, $context);
    $bucket = $company !== '' ? $company : 'none';
    foreach ($reports as $report) {
        if (!isset($companies[$bucket][$report])) {
            return;
        }
    }
    if ($company === '') {
        jeab_contacts_link_by_email_remain_all($companies, $bucket, $reports, 'no_company', $id);

        return;
    }
    $emailHit = jeab_contacts_link_by_email_row_email_hit($row, $json, $cols);
    $email = $emailHit['email'];
    if ($email === '') {
        $skip = (string) ($emailHit['skip'] ?? '');
        jeab_contacts_link_by_email_remain_all($companies, $bucket, $reports, $skip !== '' ? $skip : 'no_email', $id);

        return;
    }
    $hits = $index[$company][$email] ?? [];
    $ids = array_keys($hits);
    if (count($ids) > 1) {
        jeab_contacts_link_by_email_remain_all($companies, $bucket, $reports, 'ambiguous_email', $id);

        return;
    }
    if (count($ids) !== 1) {
        jeab_contacts_link_by_email_remain_all($companies, $bucket, $reports, 'no_match', $id);

        return;
    }
    $contactId = (string) $ids[0];
    $sets = [];
    $hasColumn = isset($cols['contact_id']);
    $touchedJson = false;
    $hadJsonKey = false;
    $nextJson = null;
    if (is_array($json) && isset($cols['row_json']) && is_string($rawJson)) {
        $hadJsonKey = array_key_exists('contact_id', $json);
        $json['contact_id'] = $contactId;
        $nextJson = jeab_contacts_link_by_email_encode($json);
        if ($nextJson === null) {
            jeab_contacts_link_by_email_remain_all($companies, $bucket, $reports, 'no_match', $id);

            return;
        }
        $sets['row_json'] = $nextJson;
        $touchedJson = true;
    } elseif (!$hasColumn) {
        jeab_contacts_link_by_email_remain_all($companies, $bucket, $reports, 'no_match', $id);

        return;
    }
    if ($hasColumn) {
        $sets['contact_id'] = $contactId;
    }
    foreach ($reports as $report) {
        $companies[$bucket][$report]['would_link']['ids'][] = [
            'id' => $id,
            'contact_id' => $contactId,
            'table' => $physical,
        ];
    }
    $writes[] = [
        'table' => $physical,
        'id' => $id,
        'contact_id' => $contactId,
        'company' => $bucket,
        'report' => $physical,
        'is_case' => $isCase,
        'has_column' => $hasColumn,
        'prev_column' => $hasColumn ? ($row['contact_id'] ?? null) : null,
        'prev_json' => $touchedJson ? $rawJson : null,
        'next_json' => $touchedJson ? $nextJson : null,
        'touched_json' => $touchedJson,
        'had_json_key' => $hadJsonKey,
        'json_fp' => $touchedJson ? jeab_contacts_link_by_email_fp($json) : '',
        'sets' => $sets,
    ];
}

/**
 * @param array<string, array<string, mixed>> $companies
 */
function jeab_contacts_link_by_email_remain(array &$companies, string $company, string $report, string $reason, string $id): void
{
    if (!isset($companies[$company][$report]['remaining'][$reason])) {
        return;
    }
    $companies[$company][$report]['remaining'][$reason]['ids'][] = $id;
}

/**
 * @param array<string, array<string, array<string, true>>> $index
 * @param array<string, array<string, mixed>> $companies
 * @param list<array<string, mixed>> $writes
 */
function jeab_contacts_link_by_email_scan(PDO $pdo, string $physical, array $index, array &$companies, array &$writes, array $context): void
{
    if (!in_array($physical, ['inkorg_list', 'inkorg_threads'], true)) {
        return;
    }
    $cols = jeab_contacts_sole_audit_columns($pdo, $physical);
    if (!isset($cols['id'])) {
        return;
    }
    $select = ['id'];
    foreach (['contact_id', 'company', 'customer_email', 'row_json'] as $col) {
        if (isset($cols[$col])) {
            $select[] = $col;
        }
    }
    $sql = 'SELECT ' . implode(', ', array_map('jeab_contacts_link_by_email_ident', $select))
        . ' FROM ' . jeab_contacts_link_by_email_ident($physical);
    try {
        $stmt = $pdo->query($sql);
        $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        return;
    }
    foreach ($rows as $row) {
        if (is_array($row)) {
            jeab_contacts_link_by_email_consider($physical, $row, $cols, $index, $companies, $writes, $context);
        }
    }
}

/**
 * Skriver samma contact_id på syskonraden med samma id när den saknar länk.
 * Öppna ärendet läser trådens row_json. Listan läser contact_id-kolumnen.
 *
 * @param list<array<string, mixed>> $writes
 */
function jeab_contacts_link_by_email_mirror(PDO $pdo, array &$writes): void
{
    $planned = [];
    foreach ($writes as $write) {
        $planned[(string) ($write['table'] ?? '') . "\0" . (string) ($write['id'] ?? '')] = true;
    }
    $extra = [];
    foreach ($writes as $write) {
        if (!empty($write['mirror'])) {
            continue;
        }
        $table = (string) ($write['table'] ?? '');
        $other = $table === 'inkorg_list' ? 'inkorg_threads' : ($table === 'inkorg_threads' ? 'inkorg_list' : '');
        $id = (string) ($write['id'] ?? '');
        if ($other === '' || $id === '' || isset($planned[$other . "\0" . $id])) {
            continue;
        }
        $built = jeab_contacts_link_by_email_mirror_write(
            $pdo,
            $other,
            $id,
            (string) ($write['contact_id'] ?? ''),
            (string) ($write['company'] ?? ''),
            $table
        );
        if ($built === null) {
            continue;
        }
        $extra[] = $built;
        $planned[$other . "\0" . $id] = true;
    }
    foreach ($extra as $row) {
        $writes[] = $row;
    }
}

/**
 * @return array<string, mixed>|null
 */
function jeab_contacts_link_by_email_mirror_write(PDO $pdo, string $table, string $id, string $contactId, string $company, string $mirrorOf): ?array
{
    if ($contactId === '' || $id === '' || str_contains($id, '@') || preg_match('/\s/u', $id) === 1) {
        return null;
    }
    if (!in_array($table, ['inkorg_list', 'inkorg_threads'], true)) {
        return null;
    }
    $cols = jeab_contacts_sole_audit_columns($pdo, $table);
    if (!isset($cols['id'])) {
        return null;
    }
    $select = ['id'];
    foreach (['contact_id', 'row_json'] as $col) {
        if (isset($cols[$col])) {
            $select[] = $col;
        }
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', array_map('jeab_contacts_link_by_email_ident', $select))
            . ' FROM ' . jeab_contacts_link_by_email_ident($table) . ' WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($row)) {
        return null;
    }
    $json = null;
    $rawJson = null;
    if (isset($cols['row_json']) && is_string($row['row_json'] ?? null) && $row['row_json'] !== '') {
        $rawJson = (string) $row['row_json'];
        $decoded = json_decode($rawJson, true);
        $json = is_array($decoded) ? $decoded : null;
    }
    if (jeab_contacts_link_by_email_has_link($row, $json, $cols)) {
        return null;
    }
    $sets = [];
    $hasColumn = isset($cols['contact_id']);
    $touchedJson = false;
    $hadJsonKey = false;
    $nextJson = null;
    if (is_array($json) && isset($cols['row_json']) && is_string($rawJson)) {
        $hadJsonKey = array_key_exists('contact_id', $json);
        $json['contact_id'] = $contactId;
        $nextJson = jeab_contacts_link_by_email_encode($json);
        if ($nextJson === null) {
            return null;
        }
        $sets['row_json'] = $nextJson;
        $touchedJson = true;
    } elseif (!$hasColumn) {
        return null;
    }
    if ($hasColumn) {
        $sets['contact_id'] = $contactId;
    }
    if ($sets === []) {
        return null;
    }

    return [
        'table' => $table,
        'id' => $id,
        'contact_id' => $contactId,
        'company' => $company,
        'report' => $table,
        'is_case' => false,
        'has_column' => $hasColumn,
        'prev_column' => $hasColumn ? ($row['contact_id'] ?? null) : null,
        'prev_json' => $touchedJson ? $rawJson : null,
        'next_json' => $touchedJson ? $nextJson : null,
        'touched_json' => $touchedJson,
        'had_json_key' => $hadJsonKey,
        'json_fp' => $touchedJson && is_array($json) ? jeab_contacts_link_by_email_fp($json) : '',
        'sets' => $sets,
        'mirror' => true,
        'mirror_of' => $mirrorOf,
    ];
}

/**
 * @param array<string, mixed> $write
 */
function jeab_contacts_link_by_email_apply(PDO $pdo, array $write): bool
{
    $table = (string) ($write['table'] ?? '');
    if (!in_array($table, ['inkorg_list', 'inkorg_threads'], true)) {
        return false;
    }
    $sets = is_array($write['sets'] ?? null) ? $write['sets'] : [];
    if ($sets === []) {
        return false;
    }
    $sqlSets = [];
    $params = [];
    foreach ($sets as $col => $value) {
        if (!in_array((string) $col, ['contact_id', 'row_json'], true)) {
            return false;
        }
        $sqlSets[] = jeab_contacts_link_by_email_ident((string) $col) . ' = ?';
        $params[] = $value;
    }
    $wheres = ['id = ?'];
    $params[] = (string) $write['id'];
    if (!empty($write['has_column'])) {
        if (!array_key_exists('prev_column', $write) || $write['prev_column'] === null) {
            $wheres[] = 'contact_id IS NULL';
        } else {
            $wheres[] = 'contact_id = ?';
            $params[] = (string) $write['prev_column'];
        }
    }
    if (!empty($write['touched_json'])) {
        $wheres[] = 'row_json = ?';
        $params[] = (string) $write['prev_json'];
    }
    $sql = 'UPDATE ' . jeab_contacts_link_by_email_ident($table)
        . ' SET ' . implode(', ', $sqlSets)
        . ' WHERE ' . implode(' AND ', $wheres);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount() === 1;
}

function jeab_contacts_link_by_email_undo_dir(): string
{
    if (!function_exists('jeab_data_dir')) {
        return '';
    }
    $dir = rtrim(jeab_data_dir(), '/');

    return $dir === '' ? '' : $dir . '/contacts-link-by-email';
}

/**
 * @param list<array<string, mixed>> $rows
 */
function jeab_contacts_link_by_email_write_undo(string $undoId, array $rows): void
{
    $dir = jeab_contacts_link_by_email_undo_dir();
    if ($dir === '' || preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    $payload = [
        'source' => 'contacts.link_by_email',
        'undo_id' => $undoId,
        'rows' => $rows,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    $path = $dir . '/' . $undoId . '.json';
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
}

/**
 * @return array<string, mixed>|null
 */
function jeab_contacts_link_by_email_read_undo(string $undoId): ?array
{
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return null;
    }
    $dir = jeab_contacts_link_by_email_undo_dir();
    if ($dir === '') {
        return null;
    }
    $path = $dir . '/' . $undoId . '.json';
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $write
 * @return array<string, mixed>
 */
function jeab_contacts_link_by_email_undo_row(array $write): array
{
    return [
        'table' => (string) $write['table'],
        'id' => (string) $write['id'],
        'contact_id' => (string) $write['contact_id'],
        'company' => (string) $write['company'],
        'report' => (string) $write['report'],
        'has_column' => !empty($write['has_column']),
        'touched_json' => !empty($write['touched_json']),
        'had_json_key' => !empty($write['had_json_key']),
        'json_fp' => (string) ($write['json_fp'] ?? ''),
    ];
}

/**
 * @param array<string, array<string, mixed>> $companies
 */
function jeab_contacts_link_by_email_finish_counts(array &$companies): void
{
    foreach ($companies as $company => $tables) {
        if (!is_array($tables)) {
            continue;
        }
        foreach ($tables as $table => $block) {
            if (!is_array($block)) {
                continue;
            }
            $links = is_array($block['would_link']['ids'] ?? null) ? $block['would_link']['ids'] : [];
            usort($links, static function (array $a, array $b): int {
                return [(string) ($a['table'] ?? ''), (string) ($a['id'] ?? ''), (string) ($a['contact_id'] ?? '')]
                    <=> [(string) ($b['table'] ?? ''), (string) ($b['id'] ?? ''), (string) ($b['contact_id'] ?? '')];
            });
            $companies[$company][$table]['would_link']['ids'] = $links;
            $companies[$company][$table]['would_link']['count'] = count($links);
            $remaining = is_array($block['remaining'] ?? null) ? $block['remaining'] : [];
            foreach (jeab_contacts_link_by_email_reasons() as $reason) {
                $ids = is_array($remaining[$reason]['ids'] ?? null) ? $remaining[$reason]['ids'] : [];
                $ids = array_map('strval', $ids);
                sort($ids);
                $companies[$company][$table]['remaining'][$reason] = [
                    'count' => count($ids),
                    'ids' => array_values($ids),
                ];
            }
        }
    }
}

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function jeab_contacts_link_by_email_scrub(array $node): array
{
    $allow = [
        'contacts.link_by_email',
        'contacts.link_by_email_undo',
        'Fel token.',
        'Ingen lagring.',
        'Kunde inte skriva ångerfilen.',
        'Ångerfilen saknas.',
        'Körningen misslyckades.',
        'inkorg_list',
        'inkorg_threads',
        'inkorg_cases',
        'jarfalla',
        'malmo',
        'none',
        'ambiguous_email',
        'no_email',
        'no_match',
        'no_company',
        'changed',
    ];
    $out = [];
    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $out[(string) $key] = jeab_contacts_link_by_email_scrub($value);
            continue;
        }
        if (is_int($value) || is_bool($value)) {
            $out[(string) $key] = $value;
            continue;
        }
        if ($value === '') {
            $out[(string) $key] = '';
            continue;
        }
        if (!is_string($value)) {
            continue;
        }
        if (in_array($value, $allow, true) || preg_match('/^[A-Za-z0-9_.:+=\/-]{1,191}$/', $value) === 1) {
            $out[(string) $key] = $value;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_link_by_email(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_link_by_email_dry($input);
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return jeab_contacts_link_by_email_scrub([
            'ok' => false,
            'error' => 'Ingen lagring.',
            'action' => 'contacts.link_by_email',
            'dry' => $dry,
            'written' => false,
        ]);
    }
    try {
        return jeab_contacts_link_by_email_scrub(jeab_contacts_link_by_email_run($store->pdo(), $dry));
    } catch (Throwable $e) {
        error_log('contacts.link_by_email: ' . $e->getMessage());

        return jeab_contacts_link_by_email_scrub([
            'ok' => false,
            'error' => 'Körningen misslyckades.',
            'action' => 'contacts.link_by_email',
            'dry' => $dry,
            'written' => false,
        ]);
    }
}

/**
 * @return array<string, mixed>
 */
function jeab_contacts_link_by_email_run(PDO $pdo, bool $dry): array
{
    $index = jeab_contacts_link_by_email_index($pdo);
    $companies = jeab_contacts_link_by_email_blank_companies();
    $writes = [];
    if (!function_exists('jeab_contacts_link_backfill_thread_context')) {
        require_once __DIR__ . '/contacts_link_backfill.php';
    }
    $context = jeab_contacts_link_backfill_thread_context($pdo);
    foreach (['inkorg_list', 'inkorg_threads'] as $table) {
        jeab_contacts_link_by_email_scan($pdo, $table, $index, $companies, $writes, $context);
    }
    jeab_contacts_link_by_email_mirror($pdo, $writes);
    $base = [
        'ok' => true,
        'action' => 'contacts.link_by_email',
        'dry' => true,
        'written' => false,
        'undo_id' => '',
        'companies' => $companies,
    ];
    if ($dry) {
        jeab_contacts_link_by_email_finish_counts($base['companies']);

        return $base;
    }
    if ($writes === []) {
        jeab_contacts_link_by_email_finish_counts($base['companies']);
        $base['dry'] = false;

        return $base;
    }
    $undoId = bin2hex(random_bytes(8));
    $planned = [];
    foreach ($writes as $write) {
        $planned[] = jeab_contacts_link_by_email_undo_row($write);
    }
    jeab_contacts_link_by_email_write_undo($undoId, $planned);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $applied = [];
        $appliedKeysDuring = [];
        foreach ($writes as $write) {
            if (!empty($write['mirror'])) {
                $parent = (string) ($write['mirror_of'] ?? '') . "\0" . (string) ($write['id'] ?? '');
                if (!isset($appliedKeysDuring[$parent])) {
                    continue;
                }
            }
            if (jeab_contacts_link_by_email_apply($pdo, $write)) {
                $applied[] = $write;
                $appliedKeysDuring[(string) $write['table'] . "\0" . (string) $write['id']] = true;
            }
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $dir = jeab_contacts_link_by_email_undo_dir();
        if ($dir !== '') {
            @unlink($dir . '/' . $undoId . '.json');
        }
        throw $e;
    }
    $appliedKeys = [];
    $undoRows = [];
    foreach ($applied as $write) {
        $appliedKeys[(string) $write['table'] . "\0" . (string) $write['id']] = true;
        $undoRows[] = jeab_contacts_link_by_email_undo_row($write);
    }
    if ($undoRows === []) {
        $dir = jeab_contacts_link_by_email_undo_dir();
        if ($dir !== '') {
            @unlink($dir . '/' . $undoId . '.json');
        }
        $undoId = '';
    } else {
        jeab_contacts_link_by_email_write_undo($undoId, $undoRows);
    }
    $linked = [];
    foreach ($base['companies'] as $company => $tables) {
        foreach ($tables as $report => $block) {
            $keep = [];
            foreach ($block['would_link']['ids'] as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $physical = $report === 'inkorg_cases' ? null : $report;
                $kept = false;
                foreach ($applied as $write) {
                    if ((string) $write['id'] !== (string) ($link['id'] ?? '')) {
                        continue;
                    }
                    if ((string) ($link['table'] ?? '') !== '' && (string) $write['table'] !== (string) $link['table']) {
                        continue;
                    }
                    if ((string) $write['company'] !== (string) $company) {
                        continue;
                    }
                    $sameReport = (string) $write['report'] === (string) $report
                        || ($report === 'inkorg_cases' && !empty($write['is_case']));
                    if (!$sameReport) {
                        continue;
                    }
                    if ($physical !== null && (string) $write['table'] !== $physical) {
                        continue;
                    }
                    $kept = true;
                    break;
                }
                if ($kept) {
                    $keep[] = $link;
                }
            }
            $linked[$company][$report] = $keep;
        }
    }
    foreach ($writes as $write) {
        if (!empty($write['mirror'])) {
            continue;
        }
        $key = (string) $write['table'] . "\0" . (string) $write['id'];
        if (isset($appliedKeys[$key])) {
            continue;
        }
        $company = (string) $write['company'];
        $report = (string) $write['report'];
        jeab_contacts_link_by_email_remain($base['companies'], $company, $report, 'no_match', (string) $write['id']);
        if (!empty($write['is_case'])) {
            jeab_contacts_link_by_email_remain($base['companies'], $company, 'inkorg_cases', 'no_match', (string) $write['id']);
        }
    }
    foreach ($linked as $company => $tables) {
        foreach ($tables as $report => $ids) {
            $base['companies'][$company][$report]['would_link']['ids'] = $ids;
        }
    }
    jeab_contacts_link_by_email_finish_counts($base['companies']);
    $base['dry'] = false;
    $base['written'] = $undoRows !== [];
    $base['undo_id'] = $undoId;

    return $base;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_link_by_email_undo(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_link_by_email_dry($input);
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    $missing = static function (bool $dryRun, string $id) : array {
        return jeab_contacts_link_by_email_scrub([
            'ok' => false,
            'error' => 'Ångerfilen saknas.',
            'action' => 'contacts.link_by_email_undo',
            'dry' => $dryRun,
            'written' => false,
            'undo_id' => preg_match('/^[a-f0-9]{16}$/', $id) === 1 ? $id : '',
        ]);
    };
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return $missing($dry, $undoId);
    }
    $file = jeab_contacts_link_by_email_read_undo($undoId);
    if (!is_array($file) || (string) ($file['source'] ?? '') !== 'contacts.link_by_email' || (string) ($file['undo_id'] ?? '') !== $undoId) {
        return $missing($dry, $undoId);
    }
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return jeab_contacts_link_by_email_scrub([
            'ok' => false,
            'error' => 'Ingen lagring.',
            'action' => 'contacts.link_by_email_undo',
            'dry' => $dry,
            'written' => false,
            'undo_id' => $undoId,
        ]);
    }
    try {
        return jeab_contacts_link_by_email_scrub(jeab_contacts_link_by_email_undo_run($store->pdo(), $file, $dry));
    } catch (Throwable $e) {
        error_log('contacts.link_by_email_undo: ' . $e->getMessage());

        return jeab_contacts_link_by_email_scrub([
            'ok' => false,
            'error' => 'Körningen misslyckades.',
            'action' => 'contacts.link_by_email_undo',
            'dry' => $dry,
            'written' => false,
            'undo_id' => $undoId,
        ]);
    }
}

/**
 * @param array<string, mixed> $file
 * @return array<string, mixed>
 */
function jeab_contacts_link_by_email_undo_run(PDO $pdo, array $file, bool $dry): array
{
    $undoId = (string) ($file['undo_id'] ?? '');
    $rows = is_array($file['rows'] ?? null) ? $file['rows'] : [];
    $companies = jeab_contacts_link_by_email_blank_companies();
    $plan = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $table = (string) ($row['table'] ?? '');
        $id = (string) ($row['id'] ?? '');
        $contactId = (string) ($row['contact_id'] ?? '');
        $company = (string) ($row['company'] ?? '');
        $report = (string) ($row['report'] ?? '');
        if (!in_array($table, ['inkorg_list', 'inkorg_threads'], true)) {
            continue;
        }
        if (!in_array($report, jeab_contacts_link_by_email_tables(), true)) {
            $report = $table;
        }
        if (!isset($companies[$company][$report])) {
            $company = 'none';
        }
        $state = jeab_contacts_link_by_email_current($pdo, $table, $id);
        if (!jeab_contacts_link_by_email_still($state, $row)) {
            if (isset($companies[$company][$report])) {
                $companies[$company][$report]['skipped_ids'][] = $id;
            }
            continue;
        }
        $companies[$company][$report]['would_link']['ids'][] = [
            'id' => $id,
            'contact_id' => $contactId,
            'table' => $table,
        ];
        $plan[] = ['row' => $row, 'state' => $state];
    }
    $out = [
        'ok' => true,
        'action' => 'contacts.link_by_email_undo',
        'dry' => true,
        'written' => false,
        'undo_id' => $undoId,
        'companies' => $companies,
    ];
    if ($dry) {
        jeab_contacts_link_by_email_undo_counts($out['companies']);

        return $out;
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $done = [];
        foreach ($plan as $item) {
            if (jeab_contacts_link_by_email_unlink($pdo, $item['row'], $item['state'])) {
                $done[(string) $item['row']['table'] . "\0" . (string) $item['row']['id']] = true;
            }
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    foreach ($out['companies'] as $company => $tables) {
        foreach ($tables as $report => $block) {
            $keep = [];
            foreach ($block['would_link']['ids'] as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $hit = false;
                foreach ($plan as $item) {
                    $row = $item['row'];
                    if ((string) ($row['id'] ?? '') !== (string) ($link['id'] ?? '')) {
                        continue;
                    }
                    if ((string) ($link['table'] ?? '') !== '' && (string) ($row['table'] ?? '') !== (string) $link['table']) {
                        continue;
                    }
                    if ((string) ($row['company'] ?? '') !== (string) $company && $company !== 'none') {
                        continue;
                    }
                    if ((string) ($row['report'] ?? '') !== (string) $report) {
                        continue;
                    }
                    $key = (string) ($row['table'] ?? '') . "\0" . (string) ($row['id'] ?? '');
                    $hit = isset($done[$key]);
                    break;
                }
                if ($hit) {
                    $keep[] = $link;
                } else {
                    $out['companies'][$company][$report]['skipped_ids'][] = (string) ($link['id'] ?? '');
                }
            }
            $out['companies'][$company][$report]['would_link']['ids'] = $keep;
        }
    }
    jeab_contacts_link_by_email_undo_counts($out['companies']);
    $out['dry'] = false;
    $out['written'] = $done !== [];

    return $out;
}

/**
 * Ångersvaret använder would_unlink i stället för would_link.
 * Oförändrade rader som inte längre matchar körningen ligger under remaining.no_match.
 *
 * @param array<string, array<string, mixed>> $companies
 */
function jeab_contacts_link_by_email_undo_counts(array &$companies): void
{
    foreach ($companies as $company => $tables) {
        if (!is_array($tables)) {
            continue;
        }
        foreach ($tables as $table => $block) {
            if (!is_array($block)) {
                continue;
            }
            $skipped = is_array($block['skipped_ids'] ?? null) ? $block['skipped_ids'] : [];
            $skipped = array_values(array_map('strval', $skipped));
            sort($skipped);
            $companies[$company][$table]['skipped'] = [
                'count' => count($skipped),
                'ids' => $skipped,
                'reason' => 'changed',
            ];
            unset($companies[$company][$table]['skipped_ids']);
        }
    }
    jeab_contacts_link_by_email_finish_counts($companies);
    foreach ($companies as $company => $tables) {
        foreach ($tables as $table => $block) {
            $companies[$company][$table]['would_unlink'] = $block['would_link'];
            unset($companies[$company][$table]['would_link']);
        }
    }
}

/**
 * @return array{column:?string,json:?array<string,mixed>,raw_json:?string,has_column:bool,has_json:bool}
 */
function jeab_contacts_link_by_email_current(PDO $pdo, string $table, string $id): array
{
    $empty = ['column' => null, 'json' => null, 'raw_json' => null, 'has_column' => false, 'has_json' => false];
    if (!in_array($table, ['inkorg_list', 'inkorg_threads'], true) || $id === '' || str_contains($id, '@') || preg_match('/\s/u', $id) === 1) {
        return $empty;
    }
    $cols = jeab_contacts_sole_audit_columns($pdo, $table);
    if (!isset($cols['id'])) {
        return $empty;
    }
    $select = ['id'];
    $hasColumn = isset($cols['contact_id']);
    $hasJson = isset($cols['row_json']);
    if ($hasColumn) {
        $select[] = 'contact_id';
    }
    if ($hasJson) {
        $select[] = 'row_json';
    }
    $sql = 'SELECT ' . implode(', ', array_map('jeab_contacts_link_by_email_ident', $select))
        . ' FROM ' . jeab_contacts_link_by_email_ident($table) . ' WHERE id = ?';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return $empty;
    }
    if (!is_array($row)) {
        return $empty;
    }
    $json = null;
    $raw = null;
    if ($hasJson && is_string($row['row_json'] ?? null)) {
        $raw = (string) $row['row_json'];
        $decoded = $raw === '' ? null : json_decode($raw, true);
        $json = is_array($decoded) ? $decoded : null;
    }

    return [
        'column' => $hasColumn ? (array_key_exists('contact_id', $row) ? (string) $row['contact_id'] : null) : null,
        'json' => $json,
        'raw_json' => $raw,
        'has_column' => $hasColumn,
        'has_json' => $hasJson,
    ];
}

/**
 * @param array{column:?string,json:?array<string,mixed>,raw_json:?string,has_column:bool,has_json:bool} $state
 * @param array<string, mixed> $saved
 */
function jeab_contacts_link_by_email_still(array $state, array $saved): bool
{
    $wrote = (string) ($saved['contact_id'] ?? '');
    if ($wrote === '') {
        return false;
    }
    if (!empty($saved['has_column'])) {
        if (empty($state['has_column']) || (string) $state['column'] !== $wrote) {
            return false;
        }
    }
    if (!empty($saved['touched_json'])) {
        if (!is_array($state['json'])) {
            return false;
        }
        if (trim((string) ($state['json']['contact_id'] ?? '')) !== $wrote) {
            return false;
        }
        if (jeab_contacts_link_by_email_fp($state['json']) !== (string) ($saved['json_fp'] ?? '')) {
            return false;
        }
    }

    return !empty($saved['has_column']) || !empty($saved['touched_json']);
}

/**
 * @param array<string, mixed> $saved
 * @param array{column:?string,json:?array<string,mixed>,raw_json:?string,has_column:bool,has_json:bool} $state
 */
function jeab_contacts_link_by_email_unlink(PDO $pdo, array $saved, array $state): bool
{
    $fresh = jeab_contacts_link_by_email_current($pdo, (string) $saved['table'], (string) $saved['id']);
    if (!jeab_contacts_link_by_email_still($fresh, $saved)) {
        return false;
    }
    $table = (string) $saved['table'];
    $sets = [];
    $params = [];
    $wheres = ['id = ?'];
    $where = [(string) $saved['id']];
    if (!empty($saved['has_column']) && !empty($fresh['has_column'])) {
        $sets[] = 'contact_id = ?';
        $params[] = '';
        $wheres[] = 'contact_id = ?';
        $where[] = (string) $saved['contact_id'];
    }
    if (!empty($saved['touched_json']) && is_array($fresh['json']) && is_string($fresh['raw_json'])) {
        $json = $fresh['json'];
        if (!empty($saved['had_json_key'])) {
            $json['contact_id'] = '';
        } else {
            unset($json['contact_id']);
        }
        $encoded = jeab_contacts_link_by_email_encode($json);
        if ($encoded === null) {
            return false;
        }
        $sets[] = 'row_json = ?';
        $params[] = $encoded;
        $wheres[] = 'row_json = ?';
        $where[] = $fresh['raw_json'];
    }
    if ($sets === []) {
        return false;
    }
    $sql = 'UPDATE ' . jeab_contacts_link_by_email_ident($table)
        . ' SET ' . implode(', ', $sets)
        . ' WHERE ' . implode(' AND ', $wheres);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $where));

    return $stmt->rowCount() === 1;
}
