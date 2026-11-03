<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/contacts_sole_audit.php';

/**
 * contacts.link_inkorg_from_reminder. dry=1 är standard.
 *
 * Rader i inkorg_list och inkorg_threads utan contact_id som en påminnelse
 * pekar på via reminders.thread_id, samma mängd som contacts.sole_audit
 * räknar som fel. Ärenden (Ny lead eller lead_customer, samma regel som
 * auditen) redovisas också som inkorg_cases. Skrivningen går till listan
 * eller tråden.
 *
 * contact_id sätts till påminnelsens contact_id när den är ifylld och
 * påminnelsens bolag är samma som radens. Tråd utan bolag tar listradens
 * bolag, samma ställe som auditen. Flera påminnelser med olika kontakter
 * på samma rad lämnas och listas. Kontakter skapas inte och slås inte ihop.
 * Inget skickas. Offerter, fakturor och mejlkopior rörs inte.
 *
 * dry listar förslag och antal per bolag, tabell och orsak.
 * dry=0 skriver data/.private/contacts-link-inkorg-from-reminder/<undo_id>.json
 * (jeab_data_dir() är den privata katalogen) och lämnar undo_id.
 * contacts.link_inkorg_from_reminder_undo är torr som standard och tar
 * bort länken bara på rader från den körningen som fortfarande är oförändrade.
 */

/** @return list<string> */
function jeab_contacts_link_inkorg_from_reminder_companies(): array
{
    return ['jarfalla', 'malmo', 'none'];
}

/** @return list<string> */
function jeab_contacts_link_inkorg_from_reminder_tables(): array
{
    return ['inkorg_list', 'inkorg_threads', 'inkorg_cases'];
}

/** @return list<string> */
function jeab_contacts_link_inkorg_from_reminder_reasons(): array
{
    return ['ambiguous_contact', 'no_contact', 'company_mismatch', 'no_company', 'changed'];
}

function jeab_contacts_link_inkorg_from_reminder_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_contacts_link_inkorg_from_reminder_ident(string $name): string
{
    if (preg_match('/^[a-z_]+$/', $name) !== 1) {
        throw new RuntimeException('Ogiltigt namn.');
    }

    return $name;
}

function jeab_contacts_link_inkorg_from_reminder_pnr_shaped(string $raw): bool
{
    $text = trim($raw);
    if ($text === '') {
        return false;
    }
    if (function_exists('jeab_norm_pnr') && jeab_norm_pnr($text) !== '') {
        return true;
    }

    return preg_match('/^\d{6}-?\d{4}$/', $text) === 1 || preg_match('/^\d{8}-?\d{4}$/', $text) === 1;
}

function jeab_contacts_link_inkorg_from_reminder_safe_id(string $id): bool
{
    if ($id === '' || str_contains($id, '@') || preg_match('/\s/u', $id) === 1) {
        return false;
    }
    if (preg_match('/^[A-Za-z0-9_.:-]{1,191}$/', $id) !== 1) {
        return false;
    }

    return !jeab_contacts_link_inkorg_from_reminder_pnr_shaped($id);
}

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function jeab_contacts_link_inkorg_from_reminder_canon(array $node): array
{
    if ($node === []) {
        return [];
    }
    if (!array_is_list($node)) {
        ksort($node);
    }
    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $node[$key] = jeab_contacts_link_inkorg_from_reminder_canon($value);
        }
    }

    return $node;
}

/** @param array<string, mixed>|null $json */
function jeab_contacts_link_inkorg_from_reminder_fp(?array $json): string
{
    if (!is_array($json)) {
        return '';
    }
    unset($json['contact_id']);
    $encoded = json_encode(
        jeab_contacts_link_inkorg_from_reminder_canon($json),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return hash('sha256', is_string($encoded) ? $encoded : '');
}

/** @param array<string, mixed> $json */
function jeab_contacts_link_inkorg_from_reminder_encode(array $json): ?string
{
    $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : null;
}

/**
 * @return array{would_link:array{count:int,ids:list<array<string, string>>},remaining:array<string, array{count:int,ids:list<string>}>}
 */
function jeab_contacts_link_inkorg_from_reminder_blank_table(): array
{
    $remaining = [];
    foreach (jeab_contacts_link_inkorg_from_reminder_reasons() as $reason) {
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
function jeab_contacts_link_inkorg_from_reminder_blank_companies(): array
{
    $out = [];
    foreach (jeab_contacts_link_inkorg_from_reminder_companies() as $company) {
        $out[$company] = [];
        foreach (jeab_contacts_link_inkorg_from_reminder_tables() as $table) {
            $out[$company][$table] = jeab_contacts_link_inkorg_from_reminder_blank_table();
        }
    }

    return $out;
}

/**
 * @param array<string, array<string, mixed>> $companies
 */
function jeab_contacts_link_inkorg_from_reminder_remain(array &$companies, string $company, string $report, string $reason, string $id): void
{
    if (!isset($companies[$company][$report]['remaining'][$reason]['ids']) || !is_array($companies[$company][$report]['remaining'][$reason]['ids'])) {
        return;
    }
    if (!in_array($id, $companies[$company][$report]['remaining'][$reason]['ids'], true)) {
        $companies[$company][$report]['remaining'][$reason]['ids'][] = $id;
    }
}

/**
 * @param array<string, true> $cols
 * @param array<string, mixed> $json
 */
function jeab_contacts_link_inkorg_from_reminder_linked(array $row, array $cols, array $json): bool
{
    if (isset($cols['contact_id']) && trim((string) ($row['contact_id'] ?? '')) !== '') {
        return true;
    }

    return trim((string) ($json['contact_id'] ?? '')) !== '';
}

/**
 * @param array<string, true> $cols
 * @param array<string, mixed> $json
 */
function jeab_contacts_link_inkorg_from_reminder_list_company(array $row, array $cols, array $json): string
{
    $company = '';
    if (isset($cols['company'])) {
        $company = jeab_contacts_sole_audit_office((string) ($row['company'] ?? ''));
    }
    if ($company === '') {
        $company = jeab_contacts_sole_audit_office((string) ($json['company'] ?? ''));
    }
    if ($company === '') {
        $company = jeab_contacts_sole_audit_office((string) ($json['brand'] ?? ''));
    }

    return $company;
}

/**
 * @return array<string, list<array{contact_id:string, company:string}>>
 */
function jeab_contacts_link_inkorg_from_reminder_reminders(PDO $pdo): array
{
    $cols = jeab_contacts_sole_audit_columns($pdo, 'reminders');
    if (!isset($cols['thread_id'])) {
        return [];
    }
    $select = ['thread_id'];
    foreach (['contact_id', 'company'] as $column) {
        if (isset($cols[$column])) {
            $select[] = $column;
        }
    }
    $sql = 'SELECT ' . implode(', ', array_map('jeab_contacts_link_inkorg_from_reminder_ident', $select)) . ' FROM reminders';
    $stmt = $pdo->query($sql);
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $threadId = trim((string) ($row['thread_id'] ?? ''));
        if (!jeab_contacts_link_inkorg_from_reminder_safe_id($threadId)) {
            continue;
        }
        $contactId = trim((string) ($row['contact_id'] ?? ''));
        if (!jeab_contacts_link_inkorg_from_reminder_safe_id($contactId)) {
            $contactId = '';
        }
        $out[$threadId][] = [
            'contact_id' => $contactId,
            'company' => jeab_contacts_sole_audit_office((string) ($row['company'] ?? '')),
        ];
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_contacts_link_inkorg_from_reminder_load(PDO $pdo, string $table): array
{
    if (!in_array($table, ['inkorg_list', 'inkorg_threads'], true)) {
        return [];
    }
    $cols = jeab_contacts_sole_audit_columns($pdo, $table);
    if (!isset($cols['id'])) {
        return [];
    }
    $select = ['id'];
    foreach (['contact_id', 'company', 'row_json'] as $column) {
        if (isset($cols[$column])) {
            $select[] = $column;
        }
    }
    $sql = 'SELECT ' . implode(', ', array_map('jeab_contacts_link_inkorg_from_reminder_ident', $select))
        . ' FROM ' . jeab_contacts_link_inkorg_from_reminder_ident($table);
    try {
        $stmt = $pdo->query($sql);
    } catch (Throwable) {
        return [];
    }
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $row
 * @return array{json:array<string, mixed>, raw:?string, usable:bool}
 */
function jeab_contacts_link_inkorg_from_reminder_json(array $row, array $cols): array
{
    if (!isset($cols['row_json']) || !is_string($row['row_json'] ?? null)) {
        return ['json' => [], 'raw' => null, 'usable' => false];
    }
    $raw = (string) $row['row_json'];
    if ($raw === '') {
        return ['json' => [], 'raw' => $raw, 'usable' => true];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['json' => [], 'raw' => $raw, 'usable' => false];
    }

    return ['json' => $decoded, 'raw' => $raw, 'usable' => true];
}

/**
 * @param list<array{contact_id:string, company:string}> $reminders
 * @return array{reason:string, contact_id:string}
 */
function jeab_contacts_link_inkorg_from_reminder_decide(array $reminders, string $rowCompany): array
{
    $ids = [];
    foreach ($reminders as $reminder) {
        $contactId = (string) ($reminder['contact_id'] ?? '');
        if ($contactId !== '') {
            $ids[$contactId] = true;
        }
    }
    if (count($ids) > 1) {
        return ['reason' => 'ambiguous_contact', 'contact_id' => ''];
    }
    if (count($ids) === 0) {
        return ['reason' => 'no_contact', 'contact_id' => ''];
    }
    $contactId = (string) array_key_first($ids);
    if ($rowCompany === '') {
        return ['reason' => 'no_company', 'contact_id' => ''];
    }
    $matched = false;
    $sawCompany = false;
    foreach ($reminders as $reminder) {
        if ((string) ($reminder['contact_id'] ?? '') !== $contactId) {
            continue;
        }
        $company = (string) ($reminder['company'] ?? '');
        if ($company !== '') {
            $sawCompany = true;
        }
        if ($company === $rowCompany) {
            $matched = true;
        }
    }
    if (!$matched) {
        return ['reason' => $sawCompany ? 'company_mismatch' : 'no_company', 'contact_id' => ''];
    }

    return ['reason' => '', 'contact_id' => $contactId];
}

/**
 * @param array<string, array<string, mixed>> $companies
 * @param list<array<string, mixed>> $writes
 * @param array<string, string> $listCompany
 * @param list<array{contact_id:string, company:string}> $reminders
 */
function jeab_contacts_link_inkorg_from_reminder_consider(
    string $physical,
    array $row,
    array $cols,
    array $reminders,
    array &$companies,
    array &$writes,
    array $listCompany
): void {
    $id = trim((string) ($row['id'] ?? ''));
    if (!jeab_contacts_link_inkorg_from_reminder_safe_id($id) || $reminders === []) {
        return;
    }
    $parsed = jeab_contacts_link_inkorg_from_reminder_json($row, $cols);
    $json = $parsed['json'];
    if (jeab_contacts_link_inkorg_from_reminder_linked($row, $cols, $json)) {
        return;
    }
    $company = $physical === 'inkorg_list'
        ? jeab_contacts_link_inkorg_from_reminder_list_company($row, $cols, $json)
        : jeab_contacts_sole_audit_office((string) ($json['company'] ?? ''));
    if ($company === '' && $physical !== 'inkorg_list') {
        $company = jeab_contacts_sole_audit_office((string) ($json['brand'] ?? ''));
    }
    if ($company === '' && $physical !== 'inkorg_list') {
        $company = (string) ($listCompany[$id] ?? '');
    }
    $bucket = $company !== '' ? $company : 'none';
    $isCase = jeab_contacts_sole_audit_case($json);
    $reports = [$physical];
    if ($isCase) {
        $reports[] = 'inkorg_cases';
    }
    foreach ($reports as $report) {
        if (!isset($companies[$bucket][$report])) {
            return;
        }
    }
    $decision = jeab_contacts_link_inkorg_from_reminder_decide($reminders, $company);
    if ($decision['reason'] !== '') {
        foreach ($reports as $report) {
            jeab_contacts_link_inkorg_from_reminder_remain($companies, $bucket, $report, $decision['reason'], $id);
        }

        return;
    }
    $contactId = $decision['contact_id'];
    $hasColumn = isset($cols['contact_id']);
    $touchedJson = false;
    $hadJsonKey = false;
    $nextJson = null;
    $rawJson = is_string($parsed['raw']) ? $parsed['raw'] : null;
    $needJson = $physical === 'inkorg_threads' || !$hasColumn || ($rawJson !== null && $rawJson !== '');
    if ($needJson) {
        if (!$parsed['usable'] || $rawJson === null) {
            foreach ($reports as $report) {
                jeab_contacts_link_inkorg_from_reminder_remain($companies, $bucket, $report, 'changed', $id);
            }

            return;
        }
        $hadJsonKey = array_key_exists('contact_id', $json);
        $json['contact_id'] = $contactId;
        $nextJson = jeab_contacts_link_inkorg_from_reminder_encode($json);
        if ($nextJson === null) {
            foreach ($reports as $report) {
                jeab_contacts_link_inkorg_from_reminder_remain($companies, $bucket, $report, 'changed', $id);
            }

            return;
        }
        $touchedJson = true;
    }
    $rawColumn = $hasColumn && array_key_exists('contact_id', $row) ? $row['contact_id'] : null;
    $columnWasNull = $hasColumn && $rawColumn === null;
    $prevColumn = $columnWasNull ? '' : trim((string) $rawColumn);
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
        'is_case' => $isCase,
        'has_column' => $hasColumn,
        'column_was_null' => $columnWasNull,
        'prev_column' => $prevColumn,
        'touched_json' => $touchedJson,
        'had_json_key' => $hadJsonKey,
        'prev_json' => $touchedJson ? (string) $parsed['raw'] : '',
        'next_json' => $touchedJson ? (string) $nextJson : '',
        'json_fp' => $touchedJson ? jeab_contacts_link_inkorg_from_reminder_fp($json) : '',
    ];
}

/**
 * @param array<string, array<string, mixed>> $companies
 * @return array<string, array<string, array<string, int>>>
 */
function jeab_contacts_link_inkorg_from_reminder_finish(array &$companies): array
{
    $counts = [];
    foreach ($companies as $company => $tables) {
        if (!is_array($tables)) {
            continue;
        }
        foreach ($tables as $table => $block) {
            if (!is_array($block)) {
                continue;
            }
            $links = is_array($block['would_link']['ids'] ?? null) ? $block['would_link']['ids'] : [];
            $clean = [];
            foreach ($links as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $clean[] = [
                    'id' => (string) ($link['id'] ?? ''),
                    'contact_id' => (string) ($link['contact_id'] ?? ''),
                    'table' => (string) ($link['table'] ?? ''),
                ];
            }
            usort($clean, static function (array $a, array $b): int {
                return [$a['table'], $a['id'], $a['contact_id']] <=> [$b['table'], $b['id'], $b['contact_id']];
            });
            $companies[$company][$table]['would_link'] = [
                'count' => count($clean),
                'ids' => $clean,
            ];
            $counts[$company][$table]['link'] = count($clean);
            foreach (jeab_contacts_link_inkorg_from_reminder_reasons() as $reason) {
                $ids = is_array($block['remaining'][$reason]['ids'] ?? null) ? $block['remaining'][$reason]['ids'] : [];
                $ids = array_map('strval', $ids);
                sort($ids);
                $companies[$company][$table]['remaining'][$reason] = [
                    'count' => count($ids),
                    'ids' => array_values($ids),
                ];
                $counts[$company][$table][$reason] = count($ids);
            }
        }
    }

    return $counts;
}

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function jeab_contacts_link_inkorg_from_reminder_scrub(array $node): array
{
    $allow = [
        'contacts.link_inkorg_from_reminder',
        'contacts.link_inkorg_from_reminder_undo',
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
        'ambiguous_contact',
        'no_contact',
        'company_mismatch',
        'no_company',
        'changed',
    ];
    $out = [];
    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $out[(string) $key] = jeab_contacts_link_inkorg_from_reminder_scrub($value);
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
        if (jeab_contacts_link_inkorg_from_reminder_pnr_shaped($value) || str_contains($value, '@')) {
            continue;
        }
        if (in_array($value, $allow, true) || preg_match('/^[A-Za-z0-9_.:-]{1,191}$/', $value) === 1) {
            $out[(string) $key] = $value;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_link_inkorg_from_reminder(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_link_inkorg_from_reminder_dry($input);
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return jeab_contacts_link_inkorg_from_reminder_scrub([
            'ok' => false,
            'error' => 'Ingen lagring.',
            'action' => 'contacts.link_inkorg_from_reminder',
            'dry' => $dry,
            'written' => false,
            'sent' => 0,
            'contacts_created' => 0,
            'contacts_merged' => 0,
        ]);
    }
    try {
        return jeab_contacts_link_inkorg_from_reminder_scrub(
            jeab_contacts_link_inkorg_from_reminder_run($store->pdo(), $dry)
        );
    } catch (Throwable $e) {
        error_log('contacts.link_inkorg_from_reminder: ' . $e->getMessage());

        return jeab_contacts_link_inkorg_from_reminder_scrub([
            'ok' => false,
            'error' => 'Körningen misslyckades.',
            'action' => 'contacts.link_inkorg_from_reminder',
            'dry' => $dry,
            'written' => false,
            'sent' => 0,
            'contacts_created' => 0,
            'contacts_merged' => 0,
        ]);
    }
}

/**
 * @return array<string, mixed>
 */
function jeab_contacts_link_inkorg_from_reminder_run(PDO $pdo, bool $dry): array
{
    $reminders = jeab_contacts_link_inkorg_from_reminder_reminders($pdo);
    $companies = jeab_contacts_link_inkorg_from_reminder_blank_companies();
    $writes = [];
    $listRows = jeab_contacts_link_inkorg_from_reminder_load($pdo, 'inkorg_list');
    $listCols = jeab_contacts_sole_audit_columns($pdo, 'inkorg_list');
    $listCompany = [];
    foreach ($listRows as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if (!jeab_contacts_link_inkorg_from_reminder_safe_id($id)) {
            continue;
        }
        $parsed = jeab_contacts_link_inkorg_from_reminder_json($row, $listCols);
        $listCompany[$id] = jeab_contacts_link_inkorg_from_reminder_list_company($row, $listCols, $parsed['json']);
    }
    foreach ($listRows as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        jeab_contacts_link_inkorg_from_reminder_consider(
            'inkorg_list',
            $row,
            $listCols,
            $reminders[$id] ?? [],
            $companies,
            $writes,
            $listCompany
        );
    }
    $threadCols = jeab_contacts_sole_audit_columns($pdo, 'inkorg_threads');
    foreach (jeab_contacts_link_inkorg_from_reminder_load($pdo, 'inkorg_threads') as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        jeab_contacts_link_inkorg_from_reminder_consider(
            'inkorg_threads',
            $row,
            $threadCols,
            $reminders[$id] ?? [],
            $companies,
            $writes,
            $listCompany
        );
    }
    $base = [
        'ok' => true,
        'action' => 'contacts.link_inkorg_from_reminder',
        'dry' => true,
        'written' => false,
        'sent' => 0,
        'contacts_created' => 0,
        'contacts_merged' => 0,
        'undo_id' => '',
        'companies' => $companies,
        'counts' => [],
    ];
    if ($dry || $writes === []) {
        $base['counts'] = jeab_contacts_link_inkorg_from_reminder_finish($base['companies']);
        $base['dry'] = $dry;

        return $base;
    }
    $undoId = bin2hex(random_bytes(8));
    $planned = [];
    foreach ($writes as $write) {
        $planned[] = jeab_contacts_link_inkorg_from_reminder_undo_row($write);
    }
    jeab_contacts_link_inkorg_from_reminder_write_undo($undoId, $planned);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $applied = [];
        foreach ($writes as $write) {
            if (jeab_contacts_link_inkorg_from_reminder_apply($pdo, $write)) {
                $applied[] = $write;
            }
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $dir = jeab_contacts_link_inkorg_from_reminder_undo_dir();
        if ($dir !== '') {
            @unlink($dir . '/' . $undoId . '.json');
        }
        throw $e;
    }
    $appliedKeys = [];
    $undoRows = [];
    foreach ($applied as $write) {
        $appliedKeys[(string) $write['table'] . "\0" . (string) $write['id']] = true;
        $undoRows[] = jeab_contacts_link_inkorg_from_reminder_undo_row($write);
    }
    if ($undoRows === []) {
        $dir = jeab_contacts_link_inkorg_from_reminder_undo_dir();
        if ($dir !== '') {
            @unlink($dir . '/' . $undoId . '.json');
        }
        $undoId = '';
    } else {
        jeab_contacts_link_inkorg_from_reminder_write_undo($undoId, $undoRows);
    }
    foreach ($writes as $write) {
        $key = (string) $write['table'] . "\0" . (string) $write['id'];
        if (isset($appliedKeys[$key])) {
            continue;
        }
        $company = (string) $write['company'];
        $id = (string) $write['id'];
        $reports = [(string) $write['table']];
        if (!empty($write['is_case'])) {
            $reports[] = 'inkorg_cases';
        }
        foreach ($reports as $report) {
            $links = $base['companies'][$company][$report]['would_link']['ids'] ?? [];
            $keep = [];
            foreach ($links as $link) {
                if (!is_array($link)) {
                    continue;
                }
                if ((string) ($link['id'] ?? '') === $id && (string) ($link['table'] ?? '') === (string) $write['table']) {
                    continue;
                }
                $keep[] = $link;
            }
            $base['companies'][$company][$report]['would_link']['ids'] = $keep;
            jeab_contacts_link_inkorg_from_reminder_remain($base['companies'], $company, $report, 'changed', $id);
        }
    }
    $base['counts'] = jeab_contacts_link_inkorg_from_reminder_finish($base['companies']);
    $base['dry'] = false;
    $base['written'] = $undoRows !== [];
    $base['undo_id'] = $undoId;

    return $base;
}

/**
 * @param array<string, mixed> $write
 */
function jeab_contacts_link_inkorg_from_reminder_apply(PDO $pdo, array $write): bool
{
    $table = (string) ($write['table'] ?? '');
    if (!in_array($table, ['inkorg_list', 'inkorg_threads'], true)) {
        return false;
    }
    $sets = [];
    $params = [];
    $wheres = ['id = ?'];
    $where = [(string) $write['id']];
    if (!empty($write['has_column'])) {
        $sets[] = 'contact_id = ?';
        $params[] = (string) $write['contact_id'];
        if (!empty($write['column_was_null'])) {
            $wheres[] = 'contact_id IS NULL';
        } else {
            $wheres[] = 'contact_id = ?';
            $where[] = (string) ($write['prev_column'] ?? '');
        }
    }
    if (!empty($write['touched_json'])) {
        $sets[] = 'row_json = ?';
        $params[] = (string) $write['next_json'];
        $wheres[] = 'row_json = ?';
        $where[] = (string) $write['prev_json'];
    }
    if ($sets === []) {
        return false;
    }
    $sql = 'UPDATE ' . jeab_contacts_link_inkorg_from_reminder_ident($table)
        . ' SET ' . implode(', ', $sets)
        . ' WHERE ' . implode(' AND ', $wheres);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $where));

    return $stmt->rowCount() === 1;
}

function jeab_contacts_link_inkorg_from_reminder_undo_dir(): string
{
    if (!function_exists('jeab_data_dir')) {
        return '';
    }
    $dir = rtrim(jeab_data_dir(), '/');

    return $dir === '' ? '' : $dir . '/contacts-link-inkorg-from-reminder';
}

/**
 * @param list<array<string, mixed>> $rows
 */
function jeab_contacts_link_inkorg_from_reminder_write_undo(string $undoId, array $rows): void
{
    $dir = jeab_contacts_link_inkorg_from_reminder_undo_dir();
    if ($dir === '' || preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    $payload = [
        'source' => 'contacts.link_inkorg_from_reminder',
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
function jeab_contacts_link_inkorg_from_reminder_read_undo(string $undoId): ?array
{
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return null;
    }
    $dir = jeab_contacts_link_inkorg_from_reminder_undo_dir();
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
function jeab_contacts_link_inkorg_from_reminder_undo_row(array $write): array
{
    return [
        'table' => (string) $write['table'],
        'id' => (string) $write['id'],
        'contact_id' => (string) $write['contact_id'],
        'company' => (string) $write['company'],
        'is_case' => !empty($write['is_case']),
        'has_column' => !empty($write['has_column']),
        'column_was_null' => !empty($write['column_was_null']),
        'prev_column' => (string) ($write['prev_column'] ?? ''),
        'touched_json' => !empty($write['touched_json']),
        'had_json_key' => !empty($write['had_json_key']),
        'json_fp' => (string) ($write['json_fp'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_link_inkorg_from_reminder_undo(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_link_inkorg_from_reminder_dry($input);
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    $missing = static function (bool $dryRun, string $id): array {
        return jeab_contacts_link_inkorg_from_reminder_scrub([
            'ok' => false,
            'error' => 'Ångerfilen saknas.',
            'action' => 'contacts.link_inkorg_from_reminder_undo',
            'dry' => $dryRun,
            'written' => false,
            'sent' => 0,
            'contacts_created' => 0,
            'contacts_merged' => 0,
            'undo_id' => preg_match('/^[a-f0-9]{16}$/', $id) === 1 ? $id : '',
        ]);
    };
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return $missing($dry, $undoId);
    }
    $file = jeab_contacts_link_inkorg_from_reminder_read_undo($undoId);
    if (!is_array($file) || (string) ($file['source'] ?? '') !== 'contacts.link_inkorg_from_reminder' || (string) ($file['undo_id'] ?? '') !== $undoId) {
        return $missing($dry, $undoId);
    }
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return jeab_contacts_link_inkorg_from_reminder_scrub([
            'ok' => false,
            'error' => 'Ingen lagring.',
            'action' => 'contacts.link_inkorg_from_reminder_undo',
            'dry' => $dry,
            'written' => false,
            'sent' => 0,
            'contacts_created' => 0,
            'contacts_merged' => 0,
            'undo_id' => $undoId,
        ]);
    }
    try {
        return jeab_contacts_link_inkorg_from_reminder_scrub(
            jeab_contacts_link_inkorg_from_reminder_undo_run($store->pdo(), $file, $dry)
        );
    } catch (Throwable $e) {
        error_log('contacts.link_inkorg_from_reminder_undo: ' . $e->getMessage());

        return jeab_contacts_link_inkorg_from_reminder_scrub([
            'ok' => false,
            'error' => 'Körningen misslyckades.',
            'action' => 'contacts.link_inkorg_from_reminder_undo',
            'dry' => $dry,
            'written' => false,
            'sent' => 0,
            'contacts_created' => 0,
            'contacts_merged' => 0,
            'undo_id' => $undoId,
        ]);
    }
}

/**
 * @param array<string, mixed> $file
 * @return array<string, mixed>
 */
function jeab_contacts_link_inkorg_from_reminder_undo_run(PDO $pdo, array $file, bool $dry): array
{
    $undoId = (string) ($file['undo_id'] ?? '');
    $rows = is_array($file['rows'] ?? null) ? $file['rows'] : [];
    $companies = jeab_contacts_link_inkorg_from_reminder_blank_companies();
    $plan = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $table = (string) ($row['table'] ?? '');
        $id = (string) ($row['id'] ?? '');
        $contactId = (string) ($row['contact_id'] ?? '');
        $company = (string) ($row['company'] ?? '');
        if (!in_array($table, ['inkorg_list', 'inkorg_threads'], true) || !jeab_contacts_link_inkorg_from_reminder_safe_id($id)) {
            continue;
        }
        if (!isset($companies[$company])) {
            $company = 'none';
        }
        $reports = [$table];
        if (!empty($row['is_case'])) {
            $reports[] = 'inkorg_cases';
        }
        $state = jeab_contacts_link_inkorg_from_reminder_current($pdo, $table, $id);
        if (!jeab_contacts_link_inkorg_from_reminder_still($state, $row)) {
            foreach ($reports as $report) {
                if (isset($companies[$company][$report])) {
                    $companies[$company][$report]['skipped_ids'][] = $id;
                }
            }
            continue;
        }
        foreach ($reports as $report) {
            $companies[$company][$report]['would_link']['ids'][] = [
                'id' => $id,
                'contact_id' => $contactId,
                'table' => $table,
            ];
        }
        $plan[] = ['row' => $row, 'state' => $state, 'company' => $company, 'reports' => $reports];
    }
    $out = [
        'ok' => true,
        'action' => 'contacts.link_inkorg_from_reminder_undo',
        'dry' => true,
        'written' => false,
        'sent' => 0,
        'contacts_created' => 0,
        'contacts_merged' => 0,
        'undo_id' => $undoId,
        'companies' => $companies,
        'counts' => [],
    ];
    if (!$dry) {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $done = [];
            foreach ($plan as $item) {
                if (jeab_contacts_link_inkorg_from_reminder_unlink($pdo, $item['row'], $item['state'])) {
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
        foreach ($plan as $item) {
            $key = (string) $item['row']['table'] . "\0" . (string) $item['row']['id'];
            if (isset($done[$key])) {
                continue;
            }
            $company = (string) $item['company'];
            foreach ($item['reports'] as $report) {
                $links = $out['companies'][$company][$report]['would_link']['ids'] ?? [];
                $keep = [];
                foreach ($links as $link) {
                    if (is_array($link) && (string) ($link['id'] ?? '') === (string) $item['row']['id'] && (string) ($link['table'] ?? '') === (string) $item['row']['table']) {
                        continue;
                    }
                    $keep[] = $link;
                }
                $out['companies'][$company][$report]['would_link']['ids'] = $keep;
                $out['companies'][$company][$report]['skipped_ids'][] = (string) $item['row']['id'];
            }
        }
        $out['written'] = $done !== [];
        $out['dry'] = false;
    }
    $out['counts'] = jeab_contacts_link_inkorg_from_reminder_undo_counts($out['companies']);

    return $out;
}

/**
 * @param array<string, array<string, mixed>> $companies
 * @return array<string, array<string, array<string, int>>>
 */
function jeab_contacts_link_inkorg_from_reminder_undo_counts(array &$companies): array
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
    $counts = jeab_contacts_link_inkorg_from_reminder_finish($companies);
    foreach ($companies as $company => $tables) {
        foreach ($tables as $table => $block) {
            $companies[$company][$table]['would_unlink'] = $block['would_link'];
            unset($companies[$company][$table]['would_link']);
            $counts[$company][$table]['unlink'] = (int) ($companies[$company][$table]['would_unlink']['count'] ?? 0);
            unset($counts[$company][$table]['link']);
        }
    }

    return $counts;
}

/**
 * @return array{column:?string, json:?array<string, mixed>, raw_json:?string, has_column:bool, has_json:bool}
 */
function jeab_contacts_link_inkorg_from_reminder_current(PDO $pdo, string $table, string $id): array
{
    $empty = ['column' => null, 'json' => null, 'raw_json' => null, 'has_column' => false, 'has_json' => false];
    if (!in_array($table, ['inkorg_list', 'inkorg_threads'], true) || !jeab_contacts_link_inkorg_from_reminder_safe_id($id)) {
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
    $sql = 'SELECT ' . implode(', ', array_map('jeab_contacts_link_inkorg_from_reminder_ident', $select))
        . ' FROM ' . jeab_contacts_link_inkorg_from_reminder_ident($table) . ' WHERE id = ?';
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
        $decoded = $raw === '' ? [] : json_decode($raw, true);
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
 * @param array{column:?string, json:?array<string, mixed>, raw_json:?string, has_column:bool, has_json:bool} $state
 * @param array<string, mixed> $saved
 */
function jeab_contacts_link_inkorg_from_reminder_still(array $state, array $saved): bool
{
    $wrote = (string) ($saved['contact_id'] ?? '');
    if (!jeab_contacts_link_inkorg_from_reminder_safe_id($wrote)) {
        return false;
    }
    if (!empty($saved['has_column'])) {
        if (empty($state['has_column']) || trim((string) $state['column']) !== $wrote) {
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
        if (jeab_contacts_link_inkorg_from_reminder_fp($state['json']) !== (string) ($saved['json_fp'] ?? '')) {
            return false;
        }
    }

    return !empty($saved['has_column']) || !empty($saved['touched_json']);
}

/**
 * @param array<string, mixed> $saved
 * @param array{column:?string, json:?array<string, mixed>, raw_json:?string, has_column:bool, has_json:bool} $state
 */
function jeab_contacts_link_inkorg_from_reminder_unlink(PDO $pdo, array $saved, array $state): bool
{
    $fresh = jeab_contacts_link_inkorg_from_reminder_current($pdo, (string) $saved['table'], (string) $saved['id']);
    if (!jeab_contacts_link_inkorg_from_reminder_still($fresh, $saved)) {
        return false;
    }
    $table = (string) $saved['table'];
    $sets = [];
    $params = [];
    $wheres = ['id = ?'];
    $where = [(string) $saved['id']];
    if (!empty($saved['has_column']) && !empty($fresh['has_column'])) {
        $sets[] = 'contact_id = ?';
        $params[] = !empty($saved['column_was_null']) ? null : (string) ($saved['prev_column'] ?? '');
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
        $encoded = jeab_contacts_link_inkorg_from_reminder_encode($json);
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
    $sql = 'UPDATE ' . jeab_contacts_link_inkorg_from_reminder_ident($table)
        . ' SET ' . implode(', ', $sets)
        . ' WHERE ' . implode(' AND ', $wheres);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $where));

    return $stmt->rowCount() === 1;
}
