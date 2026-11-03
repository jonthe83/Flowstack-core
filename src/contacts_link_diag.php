<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/contacts_link_by_email.php';

/**
 * contacts.link_diag. Bara läsning. Id, lagringsnamn och orsakskoder.
 * Ingen e-post, inget namn, ingen telefon.
 *
 * För varje rad som contacts.sole_audit räknar som tom contact_id, och för
 * rader länken lägger i ett bolag som auditen utelämnar: var auditen läste,
 * var länken läste, om raden har e-post, kort sha256 av e-postnyckeln, om
 * nyckeln finns i e-postkatalogen, antal kontakter och deras bolag.
 *
 * För varje data.inkorg_contact_create-kandidat med dup_existing_email:
 * träffad kontakt, bolag, källfält och länkens dom för samma rad.
 */

/** @return list<string> */
function jeab_contacts_link_diag_row_mismatches(): array
{
    return [
        'would_link',
        'ambiguous_email',
        'no_email',
        'directory_miss',
        'directory_other_company',
        'directory_empty_company',
        'directory_mixed_company',
        'company_storage',
        'audit_omitted',
        'contact_storage',
        'company_from_list',
    ];
}

/** @return list<string> */
function jeab_contacts_link_diag_dup_mismatches(): array
{
    return [
        'dup_linker_would_link',
        'dup_linker_ambiguous',
        'dup_linker_no_email',
        'dup_linker_no_company',
        'dup_linker_not_scanned',
        'dup_linker_email_diff',
        'dup_linker_other_company',
        'dup_linker_empty_company',
        'dup_linker_mixed_company',
        'dup_linker_no_match',
    ];
}

function jeab_contacts_link_diag_hash(string $email): string
{
    if ($email === '') {
        return '';
    }

    return substr(hash('sha256', $email), 0, 12);
}

function jeab_contacts_link_diag_company_token(string $company): string
{
    return in_array($company, ['jarfalla', 'malmo'], true) ? $company : 'none';
}

/**
 * @param array<string, true> $cols
 */
function jeab_contacts_link_diag_ui_storage(string $table, array $cols): string
{
    if ($table === 'inkorg_threads') {
        return isset($cols['row_json']) ? 'sql:inkorg_threads.row_json.contact_id' : 'json:threads.json.contact_id';
    }
    if (isset($cols['contact_id'])) {
        return 'sql:inkorg_list.contact_id';
    }

    return isset($cols['row_json']) ? 'sql:inkorg_list.row_json.contact_id' : 'json:threads.json.contact_id';
}

/**
 * Auditen läser trådens contact_id bara i row_json. Listan tar kolumnen först.
 *
 * @param array<string, true> $cols
 */
function jeab_contacts_link_diag_audit_storage(string $table, array $cols): string
{
    if ($table === 'inkorg_threads') {
        return isset($cols['row_json']) ? 'sql:inkorg_threads.row_json.contact_id' : 'json:threads.json.contact_id';
    }
    if (isset($cols['contact_id'])) {
        return 'sql:inkorg_list.contact_id';
    }

    return isset($cols['row_json']) ? 'sql:inkorg_list.row_json.contact_id' : 'json:threads.json.contact_id';
}

/**
 * Länken ser kolumnen före JSON när kolumnen finns.
 *
 * @param array<string, true> $cols
 */
function jeab_contacts_link_diag_linker_storage(string $table, array $cols): string
{
    if (isset($cols['contact_id'])) {
        return 'sql:' . $table . '.contact_id';
    }
    if (isset($cols['row_json'])) {
        return 'sql:' . $table . '.row_json.contact_id';
    }

    return $table === 'inkorg_threads' ? 'json:threads.json.contact_id' : 'sql:inkorg_list.row_json.contact_id';
}

/**
 * @param array<string, true> $cols
 */
function jeab_contacts_link_diag_writes_ui(string $table, array $cols): bool
{
    if ($table === 'inkorg_threads') {
        return isset($cols['row_json']);
    }

    return isset($cols['contact_id']) || isset($cols['row_json']);
}

/**
 * @param array<string, array{company:string,emails:array<string, true>,sources?:array<string, array<string, true>>}> $dir
 * @return list<array{contact_id:string,company:string,source:string}>
 */
function jeab_contacts_link_diag_hits(array $dir, string $email): array
{
    if ($email === '') {
        return [];
    }
    $hits = [];
    foreach ($dir as $id => $info) {
        if (!is_array($info) || empty($info['emails'][$email])) {
            continue;
        }
        $fields = is_array($info['sources'][$email] ?? null) ? $info['sources'][$email] : [];
        $source = '';
        foreach (jeab_inkorg_contact_create_email_source_names() as $name) {
            if (!empty($fields[$name])) {
                $source = $name;
                break;
            }
        }
        $hits[] = [
            'contact_id' => (string) $id,
            'company' => jeab_contacts_link_diag_company_token((string) ($info['company'] ?? '')),
            'source' => $source,
        ];
    }
    usort($hits, static function (array $a, array $b): int {
        return strcmp($a['contact_id'], $b['contact_id']);
    });

    return $hits;
}

/**
 * @param list<array{contact_id:string,company:string,source:string}> $hits
 */
function jeab_contacts_link_diag_row_mismatch(string $auditCompany, string $linkerCompany, string $email, array $hits, bool $writesUi): string
{
    if (!$writesUi) {
        return 'contact_storage';
    }
    if ($auditCompany === '' && $linkerCompany !== '') {
        return 'audit_omitted';
    }
    if ($auditCompany !== '' && $linkerCompany !== '' && $auditCompany !== $linkerCompany) {
        return 'company_storage';
    }
    $company = $linkerCompany !== '' ? $linkerCompany : $auditCompany;
    if ($email === '') {
        return 'no_email';
    }
    if ($hits === []) {
        return 'directory_miss';
    }
    $same = [];
    $other = false;
    $empty = false;
    foreach ($hits as $hit) {
        $token = (string) $hit['company'];
        if ($company !== '' && $token === $company) {
            $same[(string) $hit['contact_id']] = true;
        } elseif ($token === 'none') {
            $empty = true;
        } else {
            $other = true;
        }
    }
    if (count($same) === 1) {
        return 'would_link';
    }
    if (count($same) > 1) {
        return 'ambiguous_email';
    }
    if ($other && $empty) {
        return 'directory_mixed_company';
    }
    if ($other) {
        return 'directory_other_company';
    }
    if ($empty) {
        return 'directory_empty_company';
    }

    return 'directory_miss';
}

/**
 * @return array{rows:list<array<string, mixed>>,email_hash:array<string, array<string, string>>,verdict_seed:array<string, true>}
 */
function jeab_contacts_link_diag_collect(PDO $pdo, array $dir): array
{
    if (!function_exists('jeab_contacts_link_backfill_thread_context')) {
        require_once __DIR__ . '/contacts_link_backfill.php';
    }
    $context = jeab_contacts_link_backfill_thread_context($pdo);
    $loaded = [];
    foreach (['inkorg_list', 'inkorg_threads'] as $table) {
        $cols = jeab_contacts_sole_audit_columns($pdo, $table);
        $loaded[$table] = ['cols' => $cols, 'rows' => []];
        if (!isset($cols['id'])) {
            continue;
        }
        $select = ['id'];
        foreach (['contact_id', 'company', 'customer_email', 'row_json'] as $col) {
            if (isset($cols[$col])) {
                $select[] = $col;
            }
        }
        try {
            $stmt = $pdo->query(
                'SELECT ' . implode(', ', array_map('jeab_contacts_link_by_email_ident', $select))
                . ' FROM ' . jeab_contacts_link_by_email_ident($table)
            );
            $fetched = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            $fetched = [];
        }
        if (!is_array($fetched)) {
            continue;
        }
        foreach ($fetched as $row) {
            if (is_array($row)) {
                $loaded[$table]['rows'][] = $row;
            }
        }
    }
    $listCompany = [];
    foreach ($loaded['inkorg_list']['rows'] as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $json = null;
        if (isset($loaded['inkorg_list']['cols']['row_json']) && is_string($row['row_json'] ?? null) && $row['row_json'] !== '') {
            $decoded = json_decode((string) $row['row_json'], true);
            $json = is_array($decoded) ? $decoded : null;
        }
        $hit = jeab_contacts_link_by_email_row_company_hit($row, $json, $loaded['inkorg_list']['cols']);
        if ($hit['company'] !== '') {
            $listCompany[$id] = $hit;
        }
    }
    $rows = [];
    $emailHash = ['inkorg_list' => [], 'inkorg_threads' => []];
    foreach (['inkorg_list', 'inkorg_threads'] as $table) {
        $cols = $loaded[$table]['cols'];
        foreach ($loaded[$table]['rows'] as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || str_contains($id, '@') || preg_match('/\s/u', $id) === 1) {
                continue;
            }
            $json = null;
            if (isset($cols['row_json']) && is_string($row['row_json'] ?? null) && $row['row_json'] !== '') {
                $decoded = json_decode((string) $row['row_json'], true);
                $json = is_array($decoded) ? $decoded : null;
            }
            if (jeab_contacts_link_by_email_has_link($row, $json, $cols)) {
                continue;
            }
            $direct = jeab_contacts_link_by_email_row_company_hit($row, $json, $cols);
            $auditCompany = $direct['company'];
            $auditCompanyStorage = $auditCompany !== '' ? 'sql:' . $table . '.' . $direct['field'] : '';
            if ($table === 'inkorg_threads' && $auditCompany === '' && isset($listCompany[$id])) {
                $auditCompany = (string) $listCompany[$id]['company'];
                $auditCompanyStorage = 'sql:inkorg_list.' . (string) $listCompany[$id]['field'];
            }
            $linkerCompany = $direct['company'];
            $linkerCompanyStorage = $auditCompanyStorage;
            if ($direct['company'] !== '') {
                $linkerCompanyStorage = 'sql:' . $table . '.' . $direct['field'];
            } else {
                $linkerCompany = jeab_contacts_link_by_email_resolved_company($id, $row, $json, $cols, $context);
                $listHas = false;
                foreach ($context['list'][$id] ?? [] as $company => $yes) {
                    if ($yes && (string) $company === $linkerCompany) {
                        $listHas = true;
                    }
                }
                if ($linkerCompany !== '' && $listHas) {
                    $linkerCompanyStorage = 'sql:inkorg_list.company';
                } elseif ($linkerCompany !== '' && !empty($context['messages'][$id])) {
                    $linkerCompanyStorage = 'sql:inkorg_messages.row_json.company';
                } else {
                    $linkerCompanyStorage = '';
                }
            }
            if (!in_array($auditCompany, ['jarfalla', 'malmo'], true) && !in_array($linkerCompany, ['jarfalla', 'malmo'], true)) {
                continue;
            }
            $emailHit = jeab_contacts_link_by_email_row_email_hit($row, $json, $cols);
            $email = (string) $emailHit['email'];
            $emailHash[$table][$id] = jeab_contacts_link_diag_hash($email);
            $hits = jeab_contacts_link_diag_hits($dir, $email);
            $companies = [];
            foreach ($hits as $hit) {
                $companies[(string) $hit['company']] = true;
            }
            $companyList = array_keys($companies);
            sort($companyList, SORT_STRING);
            $writesUi = jeab_contacts_link_diag_writes_ui($table, $cols);
            $fromList = $table === 'inkorg_threads' && $direct['company'] === '' && in_array($auditCompany, ['jarfalla', 'malmo'], true);
            $rows[] = [
                'id' => $id,
                'table' => $table,
                'company' => jeab_contacts_link_diag_company_token($auditCompany),
                'linker_company' => jeab_contacts_link_diag_company_token($linkerCompany),
                'audit_storage' => jeab_contacts_link_diag_audit_storage($table, $cols),
                'audit_company_storage' => $auditCompanyStorage,
                'linker_storage' => jeab_contacts_link_diag_linker_storage($table, $cols),
                'linker_company_storage' => $linkerCompanyStorage,
                'ui_storage' => jeab_contacts_link_diag_ui_storage($table, $cols),
                'has_email' => $email !== '' ? 1 : 0,
                'email_hash' => jeab_contacts_link_diag_hash($email),
                'email_storage' => $email !== '' ? 'sql:' . $table . '.' . (string) $emailHit['field'] : '',
                'in_directory' => $hits === [] ? 0 : 1,
                'contact_count' => count($hits),
                'companies' => $companyList,
                'mismatch' => jeab_contacts_link_diag_row_mismatch($auditCompany, $linkerCompany, $email, $hits, $writesUi),
                'company_from_list' => $fromList ? 1 : 0,
            ];
        }
    }
    usort($rows, static function (array $a, array $b): int {
        return [(string) $a['table'], (string) $a['id']] <=> [(string) $b['table'], (string) $b['id']];
    });

    return ['rows' => $rows, 'email_hash' => $emailHash];
}

/**
 * @param array<string, array<string, mixed>> $companies
 * @return array<string, array{linker:string,contact_id:string}>
 */
function jeab_contacts_link_diag_verdicts(array $companies): array
{
    $out = [];
    $rank = ['would_link' => 5, 'ambiguous_email' => 4, 'no_company' => 3, 'no_email' => 2, 'from_internal' => 2, 'from_platform' => 2, 'from_blocked' => 2, 'from_unchecked' => 2, 'no_match' => 1];
    foreach ($companies as $tables) {
        if (!is_array($tables)) {
            continue;
        }
        foreach (['inkorg_list', 'inkorg_threads'] as $table) {
            $block = is_array($tables[$table] ?? null) ? $tables[$table] : [];
            foreach (is_array($block['would_link']['ids'] ?? null) ? $block['would_link']['ids'] : [] as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $id = (string) ($link['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $out[$id] = ['linker' => 'would_link', 'contact_id' => (string) ($link['contact_id'] ?? '')];
            }
            $remaining = is_array($block['remaining'] ?? null) ? $block['remaining'] : [];
            foreach (jeab_contacts_link_by_email_reasons() as $reason) {
                foreach (is_array($remaining[$reason]['ids'] ?? null) ? $remaining[$reason]['ids'] : [] as $id) {
                    $id = (string) $id;
                    $current = $out[$id]['linker'] ?? '';
                    if (($rank[$current] ?? 0) >= ($rank[$reason] ?? 0)) {
                        continue;
                    }
                    $out[$id] = ['linker' => $reason, 'contact_id' => ''];
                }
            }
        }
    }

    return $out;
}

/**
 * @param list<array{contact_id:string,company:string,source:string}> $hits
 */
function jeab_contacts_link_diag_dup_mismatch(string $linker, array $hits, bool $emailDiff, string $rowCompany): string
{
    if ($emailDiff) {
        return 'dup_linker_email_diff';
    }
    if ($linker === 'would_link') {
        return 'dup_linker_would_link';
    }
    if ($linker === 'ambiguous_email') {
        return 'dup_linker_ambiguous';
    }
    if ($linker === 'no_email') {
        return 'dup_linker_no_email';
    }
    if ($linker === 'no_company') {
        return 'dup_linker_no_company';
    }
    if ($linker === 'not_scanned') {
        return 'dup_linker_not_scanned';
    }
    $other = false;
    $empty = false;
    $same = 0;
    foreach ($hits as $hit) {
        $token = (string) $hit['company'];
        if ($rowCompany !== '' && $token === $rowCompany) {
            $same++;
        } elseif ($token === 'none') {
            $empty = true;
        } else {
            $other = true;
        }
    }
    if ($other && $empty) {
        return 'dup_linker_mixed_company';
    }
    if ($other) {
        return 'dup_linker_other_company';
    }
    if ($empty) {
        return 'dup_linker_empty_company';
    }

    return 'dup_linker_no_match';
}

/**
 * @param array<string, array<string, string>> $emailHash
 * @param array<string, array{linker:string,contact_id:string}> $verdicts
 * @param array<string, array{company:string,emails:array<string, true>,sources?:array<string, array<string, true>>}> $dir
 * @return list<array<string, mixed>>
 */
function jeab_contacts_link_diag_dups(PDO $pdo, array $dir, array $emailHash, array $verdicts): array
{
    $gaps = jeab_inkorg_contact_create_gaps($pdo);
    $plan = is_array($gaps['candidates'] ?? null) ? $gaps['candidates'] : [];
    $companyOf = [];
    foreach ($plan as $id => $hint) {
        if (!is_array($hint)) {
            continue;
        }
        $companyOf[(string) $id] = jeab_contacts_link_by_email_company((string) ($hint['company'] ?? ''));
    }
    $classified = jeab_inkorg_contact_create_classify($pdo, $plan, $companyOf);
    $dups = [];
    foreach (is_array($classified['uncertain'] ?? null) ? $classified['uncertain'] : [] as $item) {
        if (!is_array($item) || (string) ($item['reason'] ?? '') !== 'dup_existing_email') {
            continue;
        }
        $id = (string) ($item['id'] ?? '');
        $hint = is_array($plan[$id] ?? null) ? $plan[$id] : [];
        $email = jeab_inkorg_contact_create_email_key((string) ($hint['email'] ?? ''));
        $createHash = jeab_contacts_link_diag_hash($email);
        $rowHash = (string) ($emailHash['inkorg_list'][$id] ?? $emailHash['inkorg_threads'][$id] ?? '');
        $emailDiff = $createHash !== '' && $rowHash !== '' && $createHash !== $rowHash;
        $verdict = $verdicts[$id] ?? ['linker' => 'not_scanned', 'contact_id' => ''];
        $hits = jeab_contacts_link_diag_hits($dir, $email);
        $rowCompany = jeab_contacts_link_diag_company_token((string) ($companyOf[$id] ?? ''));
        if ($rowCompany === 'none') {
            $rowCompany = '';
        }
        $mismatch = jeab_contacts_link_diag_dup_mismatch((string) $verdict['linker'], $hits, $emailDiff, $rowCompany);
        if ($hits === []) {
            $dups[] = [
                'id' => $id,
                'contact_id' => '',
                'company' => 'none',
                'source' => '',
                'linker' => (string) $verdict['linker'],
                'linker_contact_id' => (string) $verdict['contact_id'],
                'mismatch' => $mismatch,
            ];
            continue;
        }
        foreach ($hits as $hit) {
            $dups[] = [
                'id' => $id,
                'contact_id' => (string) $hit['contact_id'],
                'company' => (string) $hit['company'],
                'source' => (string) $hit['source'],
                'linker' => (string) $verdict['linker'],
                'linker_contact_id' => (string) $verdict['contact_id'],
                'mismatch' => $mismatch,
            ];
        }
    }
    usort($dups, static function (array $a, array $b): int {
        return [(string) $a['id'], (string) $a['contact_id']] <=> [(string) $b['id'], (string) $b['contact_id']];
    });

    return $dups;
}

/**
 * @param list<string> $keys
 * @param list<array<string, mixed>> $items
 * @return array<string, int>
 */
function jeab_contacts_link_diag_counts(array $keys, array $items, string $field): array
{
    $counts = [];
    foreach ($keys as $key) {
        $counts[$key] = 0;
    }
    $seen = [];
    foreach ($items as $item) {
        $id = (string) ($item['id'] ?? '');
        $mismatch = (string) ($item[$field] ?? '');
        if ($field === 'mismatch' && isset($item['company_from_list']) && (int) $item['company_from_list'] === 1) {
            $mark = $id . "\0" . (string) ($item['table'] ?? '') . "\0company_from_list";
            if (!isset($seen[$mark])) {
                $counts['company_from_list']++;
                $seen[$mark] = true;
            }
        }
        $mark = $id . "\0" . (string) ($item['table'] ?? '') . "\0" . $mismatch;
        if ($mismatch === '' || isset($seen[$mark]) || !isset($counts[$mismatch])) {
            continue;
        }
        $counts[$mismatch]++;
        $seen[$mark] = true;
    }

    return $counts;
}

/** @return list<string> */
function jeab_contacts_link_diag_allow(): array
{
    $allow = array_merge(
        [
            'contacts.link_diag',
            'Fel token.',
            'Ingen lagring.',
            'Körningen misslyckades.',
            'inkorg_list',
            'inkorg_threads',
            'jarfalla',
            'malmo',
            'none',
            'would_link',
            'ambiguous_email',
            'no_email',
            'no_match',
            'no_company',
            'not_scanned',
            'json:threads.json.contact_id',
        ],
        jeab_inkorg_contact_create_email_source_names(),
        jeab_contacts_link_diag_row_mismatches(),
        jeab_contacts_link_diag_dup_mismatches()
    );
    foreach (['inkorg_list', 'inkorg_threads', 'inkorg_messages'] as $table) {
        foreach ([
            'contact_id',
            'company',
            'customer_email',
            'row_json.contact_id',
            'row_json.company',
            'row_json.brand',
            'row_json.customer_email',
            'row_json.lead_customer.email',
            'row_json.email',
        ] as $field) {
            $allow[] = 'sql:' . $table . '.' . $field;
        }
    }

    return $allow;
}

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function jeab_contacts_link_diag_scrub(array $node): array
{
    $allow = jeab_contacts_link_diag_allow();
    $out = [];
    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $out[(string) $key] = jeab_contacts_link_diag_scrub($value);
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
        if (in_array($value, $allow, true) || preg_match('/^[a-f0-9]{12}$/', $value) === 1 || preg_match('/^[A-Za-z0-9_.:+=\/-]{1,191}$/', $value) === 1) {
            $out[(string) $key] = $value;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_link_diag(array $input, ?JeabStore $store = null): array
{
    unset($input);
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return jeab_contacts_link_diag_scrub([
            'ok' => false,
            'error' => 'Ingen lagring.',
            'action' => 'contacts.link_diag',
            'dry' => true,
            'written' => false,
        ]);
    }
    try {
        return jeab_contacts_link_diag_scrub(jeab_contacts_link_diag_run($store->pdo()));
    } catch (Throwable $e) {
        error_log('contacts.link_diag: ' . $e->getMessage());

        return jeab_contacts_link_diag_scrub([
            'ok' => false,
            'error' => 'Körningen misslyckades.',
            'action' => 'contacts.link_diag',
            'dry' => true,
            'written' => false,
        ]);
    }
}

/**
 * @return array<string, mixed>
 */
function jeab_contacts_link_diag_run(PDO $pdo): array
{
    $dir = jeab_inkorg_contact_create_email_directory($pdo);
    $collected = jeab_contacts_link_diag_collect($pdo, $dir);
    $dry = jeab_contacts_link_by_email_run($pdo, true);
    $verdicts = jeab_contacts_link_diag_verdicts(is_array($dry['companies'] ?? null) ? $dry['companies'] : []);
    $dups = jeab_contacts_link_diag_dups($pdo, $dir, $collected['email_hash'], $verdicts);

    return [
        'ok' => true,
        'action' => 'contacts.link_diag',
        'dry' => true,
        'written' => false,
        'rows' => $collected['rows'],
        'dups' => $dups,
        'counts' => jeab_contacts_link_diag_counts(jeab_contacts_link_diag_row_mismatches(), $collected['rows'], 'mismatch'),
        'dup_counts' => jeab_contacts_link_diag_counts(jeab_contacts_link_diag_dup_mismatches(), $dups, 'mismatch'),
    ];
}
