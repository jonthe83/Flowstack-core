<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Bolaget följer ansvarig.
 *
 * Orsak idag: tilldelning skriver inte om bolaget till personens bolag.
 * Inkorg sätter bolaget från mottagarens mejlbox när raden byggs
 * (jeab_inkorg_thread_row_stored → jeab_inkorg_thread_company i src/inkorg.php).
 * jeab_inkorg_assign skriver bara assignee. Har ärendet redan en ägare stannar
 * ett ortsbyte (jeab_inkorg_jarfalla_keep_reason), så mejlboxen ligger kvar.
 * Påminnelser skriver bolaget från formulärets nuvarande kontor
 * (index.php reminder.who, dolt fält i jeab_reminder_who_html,
 * jeab_reminder_details_from_input).
 *
 * Mottagaradressen bestämmer fortfarande bolaget när mejlet kommer in.
 * När ansvarig byts till en person i det andra bolaget flyttas ärendet
 * och påminnelsen. Kroken company.realign_by_assignee gör samma sak i efterhand.
 * Inget skickas. Offerter, fakturor och mejlkopior rörs inte. Ingenting raderas.
 */

function jeab_company_of_person_token(string $token): string
{
    $token = trim($token);
    if ($token === '' || !function_exists('jeab_people')) {
        return '';
    }
    foreach (jeab_people() as $person) {
        if (!is_array($person)) {
            continue;
        }
        $id = (string) ($person['id'] ?? '');
        $name = (string) ($person['name'] ?? '');
        if (($id !== '' && strcasecmp($id, $token) === 0) || ($name !== '' && strcasecmp($name, $token) === 0)) {
            return jeab_company_realign_norm((string) ($person['company'] ?? ''));
        }
    }
    if (!function_exists('jeab_person_alias_matches')) {
        return '';
    }
    $hits = [];
    foreach (jeab_people() as $person) {
        if (!is_array($person)) {
            continue;
        }
        if (jeab_person_alias_matches($person, $token)) {
            $company = jeab_company_realign_norm((string) ($person['company'] ?? ''));
            if ($company !== '') {
                $hits[$company] = true;
            }
        }
    }

    return count($hits) === 1 ? (string) array_key_first($hits) : '';
}

/** @param list<string> $names */
function jeab_company_for_assignee_names(array $names): ?string
{
    $votes = [];
    foreach ($names as $name) {
        $company = jeab_company_of_person_token((string) $name);
        if ($company !== '') {
            $votes[$company] = true;
        }
    }
    if (count($votes) !== 1) {
        return null;
    }

    return (string) array_key_first($votes);
}

function jeab_company_thread_display(array $meta, string $mailbox): string
{
    $locked = jeab_company_realign_norm((string) ($meta['company'] ?? ''));
    $assignee = jeab_company_of_person_token((string) ($meta['assignee'] ?? ''));
    if ($locked !== '' && $assignee !== '' && $locked === $assignee) {
        return $locked;
    }
    $mailbox = jeab_company_realign_norm($mailbox);

    return $mailbox !== '' ? $mailbox : jeab_company_realign_norm((string) ($meta['company'] ?? ''));
}

function jeab_company_case_company(string $tid): string
{
    if ($tid === '' || !function_exists('jeab_inkorg_thread_company_of')) {
        return '';
    }

    return jeab_company_realign_norm(jeab_inkorg_thread_company_of($tid));
}

function jeab_company_realign_norm(string $raw): string
{
    if (function_exists('jeab_normalize_company')) {
        return (string) (jeab_normalize_company($raw) ?? '');
    }
    $fold = strtolower(trim($raw));
    if (in_array($fold, ['jarfalla', 'jeab'], true)) {
        return 'jarfalla';
    }
    if (in_array($fold, ['malmo', 'meab'], true)) {
        return 'malmo';
    }

    return '';
}

/** @param array<string, mixed> $row */
function jeab_company_reminder_assignee_company(array $row): string
{
    $fromPerson = jeab_company_of_person_token((string) ($row['person'] ?? ''));
    if ($fromPerson !== '') {
        return $fromPerson;
    }
    $names = [];
    foreach (['assignees', 'assignee'] as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $raw = $row[$key];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }
        if (is_array($raw)) {
            foreach ($raw as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $names[] = trim($name);
                }
            }
        } elseif (is_string($raw) && trim($raw) !== '') {
            foreach (preg_split('/\s*,\s*/', trim($raw)) ?: [] as $name) {
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        if ($names !== []) {
            break;
        }
    }
    $company = jeab_company_for_assignee_names($names);

    return $company ?? '';
}

/** @param array<string, mixed> $row */
function jeab_company_row_is_case(array $row): bool
{
    if (!empty($row['deleted']) || trim((string) ($row['deleted_at'] ?? '')) !== '') {
        return false;
    }
    $lead = is_array($row['lead_customer'] ?? null) ? $row['lead_customer'] : [];
    if (!function_exists('jeab_contacts_inbox_first_text')) {
        require_once __DIR__ . '/contacts_strict.php';
    }
    $email = jeab_contacts_inbox_first_text(
        jeab_contacts_inbox_contact_email($row),
        (string) ($lead['email'] ?? ''),
        (string) ($row['customer_email'] ?? ''),
        (string) ($row['email'] ?? '')
    );
    $phone = jeab_contacts_inbox_first_text(
        jeab_contacts_inbox_contact_phone($row),
        (string) ($lead['phone'] ?? ''),
        (string) ($row['customer_phone'] ?? ''),
        (string) ($row['phone'] ?? '')
    );
    if ($email === '' && $phone === '') {
        return false;
    }
    $labels = is_array($row['labels'] ?? null) ? $row['labels'] : [];
    $type = (string) ($labels['type'] ?? $row['list_type'] ?? '');
    if ($type === 'Ny lead') {
        return true;
    }

    return $lead !== [];
}

function jeab_company_realign_pdo(): ?PDO
{
    if (function_exists('jeab_store')) {
        try {
            $store = jeab_store();
        } catch (Throwable) {
            $store = null;
        }
        if ($store instanceof JeabPdoStore) {
            return $store->pdo();
        }
    }
    if (function_exists('jeab_inkorg_pdo_store')) {
        $inkorg = jeab_inkorg_pdo_store();
        if ($inkorg instanceof JeabPdoInkorgStore) {
            return $inkorg->pdo();
        }
    }

    return null;
}

function jeab_company_realign_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/** @return array<string, mixed>|null */
function jeab_company_realign_json(?string $raw): ?array
{
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

/**
 * @return array{
 *   tables: array<string, array{count:int, ids:list<string>}>,
 *   contacts: array{count:int, ids:list<string>},
 *   undo: list<array<string, string>>
 * }
 */
function jeab_company_realign_plan(PDO $pdo): array
{
    $tables = [
        'inkorg_list' => [],
        'inkorg_threads' => [],
        'inkorg_cases' => [],
        'reminders' => [],
    ];
    $undo = [];
    $contactTargets = [];
    $listCompany = [];
    $caseIds = [];

    try {
        $stmt = $pdo->query('SELECT id, company, contact_id, row_json, list_status FROM inkorg_list');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        $rows = [];
    }
    foreach (is_array($rows) ? $rows : [] as $db) {
        if (!is_array($db)) {
            continue;
        }
        $id = trim((string) ($db['id'] ?? ''));
        if ($id === '' || (string) ($db['list_status'] ?? '') === 'deleted') {
            continue;
        }
        $json = jeab_company_realign_json(isset($db['row_json']) ? (string) $db['row_json'] : null);
        if (is_array($json) && !empty($json['deleted'])) {
            continue;
        }
        $company = jeab_company_realign_norm((string) ($db['company'] ?? ''));
        if ($company === '' && is_array($json)) {
            $company = jeab_company_realign_norm((string) ($json['company'] ?? ''));
        }
        $listCompany[$id] = $company;
        $assignee = jeab_company_of_person_token((string) (is_array($json) ? ($json['assignee'] ?? '') : ''));
        $contact = trim((string) ($db['contact_id'] ?? ''));
        if ($contact === '' && is_array($json)) {
            $contact = trim((string) ($json['contact_id'] ?? ''));
        }
        if ($assignee !== '' && $company !== '' && $assignee !== $company) {
            $tables['inkorg_list'][$id] = true;
            $undo[] = ['table' => 'inkorg_list', 'id' => $id, 'company' => $company];
            if ($contact !== '') {
                $contactTargets[$contact][$assignee] = true;
            }
        }
        if (is_array($json) && jeab_company_row_is_case($json) && $assignee !== '' && $company !== '' && $assignee !== $company) {
            $caseIds[$id] = true;
        }
    }

    try {
        $stmt = $pdo->query('SELECT id, row_json FROM inkorg_threads');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        $rows = [];
    }
    foreach (is_array($rows) ? $rows : [] as $db) {
        if (!is_array($db)) {
            continue;
        }
        $id = trim((string) ($db['id'] ?? ''));
        $json = jeab_company_realign_json(isset($db['row_json']) ? (string) $db['row_json'] : null);
        if ($id === '' || !is_array($json) || trim((string) ($json['deleted_at'] ?? '')) !== '') {
            continue;
        }
        $stored = jeab_company_realign_norm((string) ($json['company'] ?? ''));
        $company = $stored !== '' ? $stored : (string) ($listCompany[$id] ?? '');
        $assignee = jeab_company_of_person_token((string) ($json['assignee'] ?? ''));
        if ($assignee === '' || $company === '' || $assignee === $company) {
            continue;
        }
        $tables['inkorg_threads'][$id] = true;
        $undo[] = ['table' => 'inkorg_threads', 'id' => $id, 'company' => $stored];
        $contact = trim((string) ($json['contact_id'] ?? ''));
        if ($contact !== '') {
            $contactTargets[$contact][$assignee] = true;
        }
        if (jeab_company_row_is_case($json) || isset($caseIds[$id])) {
            $caseIds[$id] = true;
        }
    }
    foreach (array_keys($caseIds) as $id) {
        $tables['inkorg_cases'][(string) $id] = true;
    }

    try {
        $stmt = $pdo->query('SELECT id, company, assignee, assignees, person, contact_id, thread_id FROM reminders WHERE id > 0');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        $rows = [];
    }
    foreach (is_array($rows) ? $rows : [] as $db) {
        if (!is_array($db)) {
            continue;
        }
        $id = (string) ((int) ($db['id'] ?? 0));
        if ($id === '0') {
            continue;
        }
        $company = jeab_company_realign_norm((string) ($db['company'] ?? ''));
        $assignee = jeab_company_reminder_assignee_company($db);
        if ($assignee === '' || $company === '' || $assignee === $company) {
            continue;
        }
        $tables['reminders'][$id] = true;
        $undo[] = ['table' => 'reminders', 'id' => $id, 'company' => $company];
        $contact = trim((string) ($db['contact_id'] ?? ''));
        if ($contact !== '') {
            $contactTargets[$contact][$assignee] = true;
        }
    }

    $contactIds = [];
    foreach ($contactTargets as $contact => $targets) {
        if (count($targets) === 1) {
            $target = (string) array_key_first($targets);
            $current = jeab_company_contact_company($contact);
            if ($current !== '' && $current !== $target) {
                $contactIds[$contact] = true;
                $undo[] = ['table' => 'contacts', 'id' => $contact, 'company' => $current];
            }
        }
    }

    return [
        'tables' => array_map(static function (array $ids): array {
            $list = array_keys($ids);
            sort($list, SORT_STRING);

            return ['count' => count($list), 'ids' => $list];
        }, $tables),
        'contacts' => [
            'count' => count($contactIds),
            'ids' => array_values(array_map('strval', array_keys($contactIds))),
        ],
        'undo' => $undo,
    ];
}

function jeab_company_realign_is_case_id(PDO $pdo, string $id): bool
{
    $id = trim($id);
    if ($id === '') {
        return false;
    }
    foreach (['inkorg_threads', 'inkorg_list'] as $table) {
        try {
            $stmt = $pdo->prepare('SELECT row_json FROM ' . $table . ' WHERE id = ?');
            $stmt->execute([$id]);
            $json = jeab_company_realign_json((string) $stmt->fetchColumn());
        } catch (Throwable) {
            $json = null;
        }
        if (is_array($json) && jeab_company_row_is_case($json)) {
            return true;
        }
    }

    return false;
}

function jeab_company_contact_company(string $id): string
{
    $id = trim($id);
    if ($id === '') {
        return '';
    }
    $store = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if ($store instanceof JeabPdoContactStore) {
        $row = $store->get($id);

        return is_array($row) ? jeab_company_realign_norm((string) ($row['company'] ?? '')) : '';
    }
    if (!function_exists('jeab_kontakt_fetch')) {
        return '';
    }
    $row = jeab_kontakt_fetch($id);

    return is_array($row) ? jeab_company_realign_norm((string) ($row['company'] ?? '')) : '';
}

function jeab_company_set_contact(string $id, string $company): bool
{
    $id = trim($id);
    $company = jeab_company_realign_norm($company);
    if ($id === '' || $company === '') {
        return false;
    }
    $store = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if ($store instanceof JeabPdoContactStore) {
        $row = $store->get($id);
        if (!is_array($row)) {
            return false;
        }
        if (jeab_company_realign_norm((string) ($row['company'] ?? '')) === $company) {
            return false;
        }
        $row['company'] = $company;
        $store->upsert($id, $row);

        return true;
    }
    if (!function_exists('jeab_kontakter_mutate')) {
        return false;
    }
    $changed = false;
    jeab_kontakter_mutate(static function (array &$contacts) use ($id, $company, &$changed): void {
        if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
            return;
        }
        if (jeab_company_realign_norm((string) ($contacts[$id]['company'] ?? '')) === $company) {
            return;
        }
        $contacts[$id]['company'] = $company;
        $changed = true;
    });

    return $changed;
}

function jeab_company_write_thread_company(string $tid, string $company): void
{
    if ($tid === '' || !function_exists('jeab_inkorg_thread_write')) {
        return;
    }
    $company = jeab_company_realign_norm($company);
    jeab_inkorg_thread_write($tid, static function (array $t) use ($company): array {
        if ($company === '') {
            unset($t['company']);
        } else {
            $t['company'] = $company;
        }

        return $t;
    });
}

function jeab_company_patch_list(PDO $pdo, string $id, string $company): void
{
    $id = trim($id);
    $company = jeab_company_realign_norm($company);
    if ($id === '') {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT row_json FROM inkorg_list WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable) {
        return;
    }
    if (!is_string($raw)) {
        return;
    }
    $data = json_decode($raw, true);
    $json = $raw;
    if (is_array($data)) {
        if ($company === '') {
            unset($data['company']);
        } else {
            $data['company'] = $company;
        }
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            $json = $encoded;
        }
    }
    $pdo->prepare('UPDATE inkorg_list SET company = ?, row_json = ? WHERE id = ?')->execute([$company, $json, $id]);
}

function jeab_company_set_reminder_company(PDO $pdo, int $id, string $company): void
{
    if ($id <= 0) {
        return;
    }
    $company = jeab_company_realign_norm($company);
    if ($company === '') {
        return;
    }
    $stamp = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare('UPDATE reminders SET company = ?, updated_at = ? WHERE id = ?')->execute([$company, $stamp, $id]);
    if (function_exists('jeab_reminders_count_cache_forget')) {
        jeab_reminders_count_cache_forget($pdo);
    }
}

/** @param list<array<string, string>> $undo */
function jeab_company_realign_apply(PDO $pdo, array $undo): void
{
    $seenThread = [];
    foreach ($undo as $row) {
        if (($row['table'] ?? '') !== 'inkorg_threads') {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if ($id === '' || isset($seenThread[$id])) {
            continue;
        }
        $seenThread[$id] = true;
        $target = '';
        foreach ($undo as $other) {
            if (($other['table'] ?? '') === 'inkorg_threads' && (string) ($other['id'] ?? '') === $id) {
                continue;
            }
        }
        $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($id) : [];
        $target = jeab_company_of_person_token((string) (is_array($meta) ? ($meta['assignee'] ?? '') : ''));
        if ($target !== '') {
            jeab_company_write_thread_company($id, $target);
        }
    }
    foreach ($undo as $row) {
        if (($row['table'] ?? '') !== 'inkorg_list') {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($id) : [];
        $target = jeab_company_of_person_token((string) (is_array($meta) ? ($meta['assignee'] ?? '') : ''));
        if ($target === '') {
            try {
                $stmt = $pdo->prepare('SELECT row_json FROM inkorg_list WHERE id = ?');
                $stmt->execute([$id]);
                $json = jeab_company_realign_json((string) $stmt->fetchColumn());
                $target = jeab_company_of_person_token((string) (is_array($json) ? ($json['assignee'] ?? '') : ''));
            } catch (Throwable) {
                $target = '';
            }
        }
        if ($target !== '') {
            if (!isset($seenThread[$id])) {
                jeab_company_write_thread_company($id, $target);
                $seenThread[$id] = true;
            }
            jeab_company_patch_list($pdo, $id, $target);
        }
    }
    foreach ($undo as $row) {
        if (($row['table'] ?? '') !== 'reminders') {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT id, company, assignee, assignees, person FROM reminders WHERE id = ?');
        $stmt->execute([$id]);
        $db = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($db)) {
            continue;
        }
        $target = jeab_company_reminder_assignee_company($db);
        if ($target !== '') {
            jeab_company_set_reminder_company($pdo, $id, $target);
        }
    }
    foreach ($undo as $row) {
        if (($row['table'] ?? '') !== 'contacts') {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $target = jeab_company_contact_target($pdo, $id);
        if ($target !== '') {
            jeab_company_set_contact($id, $target);
        }
    }
}

function jeab_company_contact_target(PDO $pdo, string $id): string
{
    $votes = [];
    $add = static function (string $company) use (&$votes): void {
        $company = jeab_company_realign_norm($company);
        if ($company !== '') {
            $votes[$company] = true;
        }
    };
    try {
        $stmt = $pdo->prepare('SELECT company, assignee, assignees, person FROM reminders WHERE contact_id = ? AND id > 0');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $add(jeab_company_reminder_assignee_company($row));
            }
        }
    } catch (Throwable) {
    }
    try {
        $stmt = $pdo->query('SELECT id, row_json FROM inkorg_threads');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $db) {
            if (!is_array($db)) {
                continue;
            }
            $json = jeab_company_realign_json((string) ($db['row_json'] ?? ''));
            if (!is_array($json) || trim((string) ($json['contact_id'] ?? '')) !== $id) {
                continue;
            }
            $add(jeab_company_of_person_token((string) ($json['assignee'] ?? '')));
        }
    } catch (Throwable) {
    }
    if (count($votes) !== 1) {
        return '';
    }

    return (string) array_key_first($votes);
}

/**
 * @param array<string, array{count:int, ids:list<string>}> $tables
 * @return array<string, array{count:int, ids:list<string>, truncated?:int}>
 */
function jeab_company_realign_public_tables(array $tables): array
{
    $out = [];
    foreach (['inkorg_list', 'inkorg_threads', 'inkorg_cases', 'reminders'] as $name) {
        $block = $tables[$name] ?? ['count' => 0, 'ids' => []];
        $ids = [];
        foreach ((array) ($block['ids'] ?? []) as $id) {
            $id = jeab_company_realign_scrub((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $count = (int) ($block['count'] ?? count($ids));
        $shown = array_slice($ids, 0, 300);
        $row = ['count' => $count, 'ids' => $shown];
        if (count($ids) > 300) {
            $row['truncated'] = 1;
        }
        $out[$name] = $row;
    }

    return $out;
}

function jeab_company_realign_scrub(string $value): string
{
    $out = preg_replace('/(?<!\d)(?:(?:19|20)\d{6}[-+]?\d{4}|\d{6}[-+]\d{4})(?!\d)/', '', $value);

    return is_string($out) ? $out : '';
}

/** @param array<string, mixed> $plan */
function jeab_company_realign_body(array $plan, bool $dry, string $undoId = ''): array
{
    $contacts = is_array($plan['contacts'] ?? null) ? $plan['contacts'] : ['count' => 0, 'ids' => []];
    $ids = [];
    foreach ((array) ($contacts['ids'] ?? []) as $id) {
        $id = jeab_company_realign_scrub((string) $id);
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    sort($ids, SORT_STRING);
    $body = [
        'ok' => true,
        'action' => 'company.realign_by_assignee',
        'dry' => $dry ? 1 : 0,
        'sent' => 0,
        'would_send' => false,
        'deleted' => 0,
        'tables' => jeab_company_realign_public_tables(is_array($plan['tables'] ?? null) ? $plan['tables'] : []),
        'contacts' => ['count' => count($ids), 'ids' => $ids],
    ];
    if (!$dry && $undoId !== '') {
        $body['undo_id'] = $undoId;
    }

    return $body;
}

function jeab_company_realign_undo_dir(): string
{
    $dir = jeab_data_dir() . '/company-realign';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte spara ångringen.');
    }

    return $dir;
}

/** @param list<array<string, string>> $rows */
function jeab_company_realign_undo_put(string $id, array $rows): void
{
    $path = jeab_company_realign_undo_dir() . '/' . $id . '.json';
    $json = json_encode([
        'source' => 'company.realign_by_assignee',
        'undo_id' => $id,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte spara ångringen.');
    }
}

/** @return array<string, mixed> */
function jeab_company_realign_undo_get(string $id): array
{
    if (preg_match('/^[a-f0-9]{16}$/', $id) !== 1) {
        return [];
    }
    $path = jeab_data_dir() . '/company-realign/' . $id . '.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

/** @param array<string, mixed> $input */
function jeab_company_realign(array $input): array
{
    $dry = jeab_company_realign_dry($input);
    $pdo = jeab_company_realign_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL.', 'dry' => $dry ? 1 : 0, 'sent' => 0, 'would_send' => false, 'status' => 400];
    }
    $plan = jeab_company_realign_plan($pdo);
    if ($dry) {
        return jeab_company_realign_body($plan, true);
    }
    $undo = is_array($plan['undo'] ?? null) ? $plan['undo'] : [];
    if ($undo === []) {
        return jeab_company_realign_body($plan, false, '');
    }
    jeab_company_realign_apply($pdo, $undo);
    $id = bin2hex(random_bytes(8));
    jeab_company_realign_undo_put($id, $undo);
    $after = jeab_company_realign_plan($pdo);
    $body = jeab_company_realign_body($plan, false, $id);
    $body['remaining'] = jeab_company_realign_public_tables(is_array($after['tables'] ?? null) ? $after['tables'] : []);

    return $body;
}

/** @param array<string, mixed> $input */
function jeab_company_realign_undo(array $input): array
{
    $dry = jeab_company_realign_dry($input);
    $id = trim((string) ($input['undo_id'] ?? ''));
    $file = jeab_company_realign_undo_get($id);
    if ($file === [] || (string) ($file['source'] ?? '') !== 'company.realign_by_assignee') {
        return ['ok' => false, 'error' => 'undo saknas', 'dry' => $dry ? 1 : 0, 'sent' => 0, 'would_send' => false, 'status' => 400];
    }
    $rows = is_array($file['rows'] ?? null) ? $file['rows'] : [];
    $tables = [
        'inkorg_list' => [],
        'inkorg_threads' => [],
        'inkorg_cases' => [],
        'reminders' => [],
    ];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $table = (string) ($row['table'] ?? '');
        $rowId = (string) ($row['id'] ?? '');
        if ($rowId === '' || $table === 'contacts') {
            continue;
        }
        if ($table === 'inkorg_list' || $table === 'inkorg_threads') {
            $tables[$table][$rowId] = true;
        } elseif ($table === 'reminders') {
            $tables['reminders'][$rowId] = true;
        }
    }
    $pdo = jeab_company_realign_pdo();
    if ($pdo instanceof PDO) {
        foreach (array_keys($tables['inkorg_list'] + $tables['inkorg_threads']) as $rowId) {
            if (jeab_company_realign_is_case_id($pdo, (string) $rowId)) {
                $tables['inkorg_cases'][(string) $rowId] = true;
            }
        }
    }
    $body = [
        'ok' => true,
        'action' => 'company.realign_by_assignee_undo',
        'dry' => $dry ? 1 : 0,
        'undo_id' => $id,
        'sent' => 0,
        'would_send' => false,
        'deleted' => 0,
        'tables' => jeab_company_realign_public_tables(array_map(static function (array $ids): array {
            $list = array_keys($ids);
            sort($list, SORT_STRING);

            return ['count' => count($list), 'ids' => $list];
        }, $tables)),
    ];
    if ($dry) {
        return $body;
    }
    $pdo = jeab_company_realign_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL.', 'dry' => 0, 'sent' => 0, 'would_send' => false, 'status' => 400];
    }
    foreach ($rows as $row) {
        if (!is_array($row) || (string) ($row['table'] ?? '') !== 'inkorg_threads') {
            continue;
        }
        jeab_company_write_thread_company((string) ($row['id'] ?? ''), (string) ($row['company'] ?? ''));
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $table = (string) ($row['table'] ?? '');
        $rowId = (string) ($row['id'] ?? '');
        $company = jeab_company_realign_norm((string) ($row['company'] ?? ''));
        if ($table === 'inkorg_list' && $rowId !== '') {
            jeab_company_patch_list($pdo, $rowId, $company);
        } elseif ($table === 'reminders' && $company !== '') {
            jeab_company_set_reminder_company($pdo, (int) $rowId, $company);
        } elseif ($table === 'contacts' && $company !== '') {
            jeab_company_set_contact($rowId, $company);
        }
    }
    $body['dry'] = 0;
    $body['restored'] = 1;

    return $body;
}

function jeab_company_sync_after_thread_assign(string $tid, string $personId, string $target): void
{
    $target = jeab_company_realign_norm($target);
    $pdo = jeab_company_realign_pdo();
    if ($tid === '' || $target === '' || !$pdo instanceof PDO) {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT id, company, assignee, assignees, person, contact_id FROM reminders WHERE thread_id = ? AND id > 0');
        $stmt->execute([$tid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $rows = [];
    }
    $contacts = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $who = jeab_company_reminder_assignee_company($row);
        $same = $personId !== '' && strcasecmp(trim((string) ($row['person'] ?? '')), $personId) === 0;
        if ($who !== '' && $who !== $target && !$same) {
            continue;
        }
        if (jeab_company_realign_norm((string) ($row['company'] ?? '')) !== $target) {
            jeab_company_set_reminder_company($pdo, (int) ($row['id'] ?? 0), $target);
        }
        $contact = trim((string) ($row['contact_id'] ?? ''));
        if ($contact !== '') {
            $contacts[$contact] = true;
        }
    }
    $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
    $threadContact = trim((string) (is_array($meta) ? ($meta['contact_id'] ?? '') : ''));
    if ($threadContact !== '') {
        $contacts[$threadContact] = true;
    }
    foreach (array_keys($contacts) as $contact) {
        jeab_company_set_contact((string) $contact, $target);
    }
}

/** @param array<string, mixed> $reminder */
function jeab_company_sync_after_reminder_assign(array $reminder): void
{
    $target = jeab_company_realign_norm((string) ($reminder['company'] ?? ''));
    if ($target === '') {
        return;
    }
    $person = trim((string) ($reminder['person'] ?? ''));
    $tid = trim((string) ($reminder['thread_id'] ?? ''));
    if ($tid !== '' && function_exists('jeab_inkorg_thread_meta')) {
        $meta = jeab_inkorg_thread_meta($tid);
        $threadPerson = trim((string) (is_array($meta) ? ($meta['assignee'] ?? '') : ''));
        $threadCompany = jeab_company_of_person_token($threadPerson);
        $follow = $threadPerson === '' || $threadCompany === '' || $threadCompany === $target
            || ($person !== '' && strcasecmp($threadPerson, $person) === 0);
        if ($follow && jeab_company_case_company($tid) !== $target && function_exists('jeab_inkorg_thread_write')) {
            jeab_inkorg_thread_write($tid, static function (array $t) use ($target, $person): array {
                $current = trim((string) ($t['assignee'] ?? ''));
                if ($current === '' && $person !== '') {
                    $t['assignee'] = $person;
                }
                $t['company'] = $target;

                return $t;
            });
            $pdo = jeab_company_realign_pdo();
            if ($pdo instanceof PDO) {
                jeab_company_patch_list($pdo, $tid, $target);
            }
        }
    }
    $contact = trim((string) ($reminder['contact_id'] ?? ''));
    if ($contact !== '') {
        jeab_company_set_contact($contact, $target);
    }
}

/** @param array<string, mixed> $input */
function jeab_company_realign_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'sent' => 0, 'would_send' => false];
    }
    $action = (string) ($input['action'] ?? '');
    $result = $action === 'company.realign_by_assignee_undo'
        ? jeab_company_realign_undo($input)
        : jeab_company_realign($input);
    $result['sent'] = 0;
    $result['would_send'] = false;
    if (!isset($result['status'])) {
        $result['status'] = !empty($result['ok']) ? 200 : 400;
    }

    return $result;
}
