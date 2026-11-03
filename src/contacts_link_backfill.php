<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/contacts_sole_audit.php';

/**
 * contacts.link_backfill. dry=1 är standard.
 * Sätter contact_id bara när samma bolag har exakt en kontakt med samma
 * normaliserade e-post, eller annars samma normaliserade telefon, och
 * kontrollen går igenom: match_count_one och (other_agrees eller other_absent).
 * other_absent betyder att den andra kanalen är tom. En ifylld kanal som inte
 * pekar på samma kontakt skrivs inte; raden listas som verify_failed.
 * dry och dry=0 använder samma val. dry=1 räknar verify_pass_count och
 * verify_fail_count för alla förslag, per tabell och bolag. full=1 skriver
 * förslagen (bara id och booleska kontroller) under
 * data/.private/contacts-link-backfill/ och svarar med sökvägen.
 * Osäkra rader listas med id och lämnas.
 * Kontakter slås inte ihop och skapas inte. En ifylld länk skrivs inte över.
 * Skrivningen rör bara contact_id. Bolag härleds för en tråd utan bolag från
 * mejlbox, källa och listrad, och bara när de pekar på ett bolag. Det skrivs
 * inte. dry=0 skriver först en ångerfil under data/.private/.
 * contacts.link_backfill_undo lägger tillbaka contact_id.
 * Markören contacts.json.migrated sätts bara när ingen inkorg_contacts-rad
 * saknar länk. Dessförinnan fortsätter läsningen och svaret räknar kvarvarande.
 */

/** @return list<string> */
function jeab_contacts_link_backfill_companies(): array
{
    return ['jarfalla', 'malmo'];
}

function jeab_contacts_link_backfill_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_contacts_link_backfill_full(array $input): bool
{
    if (!array_key_exists('full', $input)) {
        return false;
    }
    $value = $input['full'];

    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

function jeab_contacts_link_backfill_company(string $raw): string
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

function jeab_contacts_link_backfill_co_key(string $company): string
{
    return $company === 'jarfalla' || $company === 'malmo' ? $company : 'none';
}

/**
 * @param array<string, mixed> $counts
 */
function jeab_contacts_link_backfill_tally(array &$counts, string $company, string $field): void
{
    $key = jeab_contacts_link_backfill_co_key($company);
    if (!isset($counts['co'][$key][$field])) {
        return;
    }
    $counts['co'][$key][$field]++;
}

/** @return array<string, array{empty:int,kept:int,linked:int,uncertain:int,verify_pass_count:int,verify_fail_count:int}> */
function jeab_contacts_link_backfill_co_counts(): array
{
    $one = static fn (): array => [
        'empty' => 0,
        'kept' => 0,
        'linked' => 0,
        'uncertain' => 0,
        'verify_pass_count' => 0,
        'verify_fail_count' => 0,
    ];

    return ['jarfalla' => $one(), 'malmo' => $one(), 'none' => $one()];
}

/**
 * Samma signaler som inkorgens bolag från mejlbox och källa, utan standardbolag.
 *
 * @param array<string, true> $set
 * @param array<string, string> $boxes
 * @param array<string, mixed> $record
 */
function jeab_contacts_link_backfill_note_record(array &$set, array $record, array $boxes): void
{
    foreach (['company', 'brand'] as $key) {
        $company = jeab_contacts_link_backfill_company((string) ($record[$key] ?? ''));
        if ($company !== '') {
            $set[$company] = true;
        }
    }
    $box = trim((string) ($record['hs_mailbox_id'] ?? ''));
    if ($box !== '' && isset($boxes[$box])) {
        $set[$boxes[$box]] = true;
    }
    $blob = trim((string) ($record['source'] ?? '') . ' ' . (string) ($record['graph_mailbox'] ?? '') . ' ' . (string) ($record['to'] ?? ''));
    if ($blob === '') {
        return;
    }
    $blob = function_exists('mb_strtolower') ? mb_strtolower($blob, 'UTF-8') : strtolower($blob);
    if (str_contains($blob, 'malmo-demo') || str_contains($blob, 'malmo')) {
        $set['malmo'] = true;
    }
    if (str_contains($blob, 'jarfalla-demo') || str_contains($blob, 'jarfalla')) {
        $set['jarfalla'] = true;
    }
}

/** @return array<string, string> */
function jeab_contacts_link_backfill_mailbox_ids(): array
{
    if (!function_exists('jeab_helpscout_mailbox_id')) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach (['jarfalla', 'malmo'] as $company) {
        $id = jeab_helpscout_mailbox_id($company);
        if ($id === null || (int) $id <= 0) {
            continue;
        }
        $key = (string) (int) $id;
        if (isset($seen[$key])) {
            unset($out[$key]);
            continue;
        }
        $seen[$key] = $company;
        $out[$key] = $company;
    }

    return $out;
}

/**
 * @return array{list:array<string, array<string, true>>,messages:array<string, array<string, true>>,boxes:array<string, string>}
 */
function jeab_contacts_link_backfill_thread_context(PDO $pdo): array
{
    $context = ['list' => [], 'messages' => [], 'boxes' => jeab_contacts_link_backfill_mailbox_ids()];
    if (jeab_contacts_sole_audit_table($pdo, 'inkorg_list')) {
        $cols = jeab_contacts_sole_audit_columns($pdo, 'inkorg_list');
        $select = ['id'];
        foreach (['company', 'row_json'] as $col) {
            if (isset($cols[$col])) {
                $select[] = $col;
            }
        }
        $stmt = $pdo->query('SELECT ' . implode(', ', array_map('jeab_contacts_link_backfill_ident', $select)) . ' FROM inkorg_list');
        $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $set = [];
            $json = isset($row['row_json']) && is_string($row['row_json']) ? json_decode($row['row_json'], true) : null;
            jeab_contacts_link_backfill_note_record($set, [
                'company' => (string) ($row['company'] ?? ''),
                'brand' => is_array($json) ? (string) ($json['brand'] ?? $json['company'] ?? '') : '',
            ], $context['boxes']);
            foreach ($set as $company => $yes) {
                if ($yes) {
                    $context['list'][$id][$company] = true;
                }
            }
        }
    }
    if (!jeab_contacts_sole_audit_table($pdo, 'inkorg_messages')) {
        return $context;
    }
    $cols = jeab_contacts_sole_audit_columns($pdo, 'inkorg_messages');
    if (!isset($cols['thread_id'], $cols['row_json'])) {
        return $context;
    }
    $mysql = jeab_contacts_sole_audit_mysql($pdo);
    $piece = static function (string $path) use ($mysql): string {
        return jeab_contacts_sole_audit_json_text($mysql, 'row_json', $path);
    };
    $sql = 'SELECT thread_id, '
        . $piece('$.source') . ' AS source, '
        . $piece('$.graph_mailbox') . ' AS graph_mailbox, '
        . $piece('$.to') . ' AS msg_to, '
        . $piece('$.company') . ' AS company, '
        . $piece('$.brand') . ' AS brand, '
        . $piece('$.hs_mailbox_id') . ' AS hs_mailbox_id '
        . 'FROM inkorg_messages';
    try {
        $stmt = $pdo->query($sql);
    } catch (Throwable) {
        return $context;
    }
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['thread_id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $set = [];
        jeab_contacts_link_backfill_note_record($set, [
            'source' => (string) ($row['source'] ?? ''),
            'graph_mailbox' => (string) ($row['graph_mailbox'] ?? ''),
            'to' => (string) ($row['msg_to'] ?? ''),
            'company' => (string) ($row['company'] ?? ''),
            'brand' => (string) ($row['brand'] ?? ''),
            'hs_mailbox_id' => (string) ($row['hs_mailbox_id'] ?? ''),
        ], $context['boxes']);
        foreach ($set as $company => $yes) {
            if ($yes) {
                $context['messages'][$id][$company] = true;
            }
        }
    }

    return $context;
}

/**
 * @param array<string, mixed>|null $json
 * @param array{list:array<string, array<string, true>>,messages:array<string, array<string, true>>,boxes:array<string, string>} $context
 * @return array{co:string,derived:bool}
 */
function jeab_contacts_link_backfill_thread_co(string $id, ?array $json, array $context): array
{
    $set = [];
    if (is_array($json)) {
        jeab_contacts_link_backfill_note_record($set, $json, $context['boxes']);
        foreach (is_array($json['messages'] ?? null) ? $json['messages'] : [] as $message) {
            if (is_array($message)) {
                jeab_contacts_link_backfill_note_record($set, $message, $context['boxes']);
            }
        }
    }
    foreach ([$context['messages'][$id] ?? [], $context['list'][$id] ?? []] as $bucket) {
        foreach ($bucket as $company => $yes) {
            if ($yes) {
                $set[(string) $company] = true;
            }
        }
    }
    $keys = array_keys($set);
    if (count($keys) !== 1) {
        return ['co' => '', 'derived' => false];
    }

    return ['co' => (string) $keys[0], 'derived' => true];
}

/**
 * @param array<string, true> $emailIds
 * @param array<string, true> $phoneIds
 * @return array{matched_by_email:bool,matched_by_phone:bool,match_count_one:bool,other_agrees:bool,other_absent:bool}
 */
function jeab_contacts_link_backfill_verify(string $via, string $target, array $emailIds, array $phoneIds, string $email, string $phone): array
{
    $byEmail = $via === 'email';
    $matched = $byEmail ? $emailIds : $phoneIds;
    $otherIds = $byEmail ? $phoneIds : $emailIds;
    $other = $byEmail ? $phone : $email;

    return [
        'matched_by_email' => $byEmail,
        'matched_by_phone' => $via === 'phone',
        'match_count_one' => count($matched) === 1 && isset($matched[$target]),
        'other_agrees' => $other !== '' && count($otherIds) === 1 && isset($otherIds[$target]),
        'other_absent' => $other === '',
    ];
}

/**
 * @param array{matched_by_email?:bool,matched_by_phone?:bool,match_count_one?:bool,other_agrees?:bool,other_absent?:bool} $verify
 */
function jeab_contacts_link_backfill_verify_ok(array $verify): bool
{
    return !empty($verify['match_count_one'])
        && (!empty($verify['other_agrees']) || !empty($verify['other_absent']));
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_link_backfill_emit(array $row, bool $verify): array
{
    $out = [
        'table' => (string) ($row['table'] ?? ''),
        'id' => (string) ($row['id'] ?? ''),
        'co' => (string) ($row['co'] ?? ''),
        'co_derived' => !empty($row['co_derived']),
    ];
    if (array_key_exists('contact_id', $row)) {
        $out['contact_id'] = (string) $row['contact_id'];
    }
    if (array_key_exists('reason', $row)) {
        $out['reason'] = (string) $row['reason'];
    }
    if ($verify && is_array($row['verify'] ?? null)) {
        $flags = [];
        foreach (['matched_by_email', 'matched_by_phone', 'match_count_one', 'other_agrees', 'other_absent'] as $key) {
            $flags[$key] = !empty($row['verify'][$key]);
        }
        $out['verify'] = $flags;
    }

    return $out;
}

function jeab_contacts_link_backfill_email(string $raw): string
{
    $email = jeab_norm_email($raw);

    return str_contains($email, '@') ? $email : '';
}

function jeab_contacts_link_backfill_phone(string $raw): string
{
    return jeab_norm_phone($raw);
}

function jeab_contacts_link_backfill_ident(string $name): string
{
    if (preg_match('/^[a-z_]+$/', $name) !== 1) {
        throw new RuntimeException('Ogiltigt namn.');
    }

    return $name;
}

/**
 * @param array<string, mixed> $json
 */
function jeab_contacts_link_backfill_path(array $json, string $path): string
{
    if (preg_match('/^\$(\.[A-Za-z_][A-Za-z0-9_]*)+$/', $path) !== 1) {
        return '';
    }
    $node = $json;
    foreach (explode('.', substr($path, 2)) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return '';
        }
        $node = $node[$key];
    }
    if (is_array($node) || is_object($node)) {
        return '';
    }

    return trim((string) $node);
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 * @param list<string> $paths
 */
function jeab_contacts_link_backfill_first(array $row, ?array $json, array $cols, string $column, array $paths): string
{
    if ($column !== '' && isset($cols[$column])) {
        $value = trim((string) ($row[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    if (!is_array($json)) {
        return '';
    }
    foreach ($paths as $path) {
        $value = jeab_contacts_link_backfill_path($json, $path);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * @param array<string, array<string, array<string, true>>> $map
 */
function jeab_contacts_link_backfill_index_add(array &$map, string $company, string $key, string $id): void
{
    if ($key === '' || $id === '') {
        return;
    }
    $map[$company][$key][$id] = true;
}

/**
 * @param array<string, array<string, array<string, true>>> $map
 * @return array<string, true>
 */
function jeab_contacts_link_backfill_index_ids(array $map, string $company, string $key, bool $global): array
{
    if ($key === '') {
        return [];
    }
    if (!$global) {
        return $map[$company][$key] ?? [];
    }
    $out = [];
    foreach ($map as $bucket) {
        foreach ($bucket[$key] ?? [] as $id => $yes) {
            if ($yes) {
                $out[(string) $id] = true;
            }
        }
    }

    return $out;
}

/**
 * @param array<string, true> $emailIds
 * @param array<string, true> $phoneIds
 * @return array{id:string,via:string}|array{reason:string}
 */
function jeab_contacts_link_backfill_pick(array $emailIds, array $phoneIds): array
{
    $emails = array_keys($emailIds);
    $phones = array_keys($phoneIds);
    sort($emails);
    sort($phones);
    if (count($emails) > 1) {
        return ['reason' => 'ambiguous_email'];
    }
    if (count($emails) === 1) {
        $id = (string) $emails[0];
        foreach ($phones as $phoneId) {
            if ((string) $phoneId !== $id) {
                return ['reason' => 'conflict'];
            }
        }

        return ['id' => $id, 'via' => 'email'];
    }
    if (count($phones) > 1) {
        return ['reason' => 'ambiguous_phone'];
    }
    if (count($phones) === 1) {
        return ['id' => (string) $phones[0], 'via' => 'phone'];
    }

    return ['reason' => 'no_match'];
}

/**
 * @return array{0:array<string, array<string, array<string, true>>>,1:array<string, array<string, array<string, true>>>}
 */
function jeab_contacts_link_backfill_contacts(PDO $pdo): array
{
    $email = [];
    $phone = [];
    $companyOf = [];
    if (!jeab_contacts_sole_audit_table($pdo, 'contacts')) {
        return [$email, $phone];
    }
    $cols = jeab_contacts_sole_audit_columns($pdo, 'contacts');
    $want = ['id', 'owner_email', 'owner_phone', 'company', 'row_json'];
    $select = [];
    foreach ($want as $col) {
        if (isset($cols[$col])) {
            $select[] = jeab_contacts_link_backfill_ident($col);
        }
    }
    if (!in_array('id', $select, true)) {
        return [$email, $phone];
    }
    $stmt = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM contacts');
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $json = null;
        if (isset($row['row_json']) && is_string($row['row_json']) && $row['row_json'] !== '') {
            $decoded = json_decode($row['row_json'], true);
            $json = is_array($decoded) ? $decoded : null;
        }
        if (is_array($json) && trim((string) ($json['deleted_at'] ?? '')) !== '') {
            continue;
        }
        $company = jeab_contacts_link_backfill_company(jeab_contacts_link_backfill_first(
            $row,
            $json,
            $cols,
            isset($cols['company']) ? 'company' : '',
            ['$.company']
        ));
        $companyOf[$id] = $company;
        $seenMail = [];
        $addMail = static function (string $raw) use (&$email, &$seenMail, $company, $id): void {
            $norm = jeab_contacts_link_backfill_email($raw);
            if ($norm === '' || isset($seenMail[$norm])) {
                return;
            }
            $seenMail[$norm] = true;
            jeab_contacts_link_backfill_index_add($email, $company, $norm, $id);
        };
        $addPhone = static function (string $raw) use (&$phone, $company, $id): void {
            jeab_contacts_link_backfill_index_add($phone, $company, jeab_contacts_link_backfill_phone($raw), $id);
        };
        $addMail((string) ($row['owner_email'] ?? ''));
        $addPhone((string) ($row['owner_phone'] ?? ''));
        if (is_array($json)) {
            foreach (is_array($json['owners'] ?? null) ? $json['owners'] : [] as $owner) {
                if (!is_array($owner)) {
                    continue;
                }
                $addMail((string) ($owner['email'] ?? ''));
                $addPhone((string) ($owner['phone'] ?? ''));
            }
            $addMail((string) ($json['email'] ?? ''));
            $addMail((string) ($json['email2'] ?? ''));
            $addMail((string) ($json['email3'] ?? ''));
            $addPhone((string) ($json['phone'] ?? $json['mobile'] ?? ''));
        }
    }
    if (isset($cols['email2']) || isset($cols['email3'])) {
        try {
            $extra = $pdo->query('SELECT id, email2, email3 FROM contacts');
            $list = $extra instanceof PDOStatement ? $extra->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = trim((string) ($item['id'] ?? ''));
                if ($id === '' || !array_key_exists($id, $companyOf)) {
                    continue;
                }
                $addMail = static function (string $raw) use (&$email, $companyOf, $id): void {
                    $norm = jeab_contacts_link_backfill_email($raw);
                    if ($norm === '') {
                        return;
                    }
                    jeab_contacts_link_backfill_index_add($email, $companyOf[$id], $norm, $id);
                };
                $addMail((string) ($item['email2'] ?? ''));
                $addMail((string) ($item['email3'] ?? ''));
            }
        } catch (Throwable) {
        }
    }
    if (jeab_contacts_sole_audit_table($pdo, 'contact_emails')) {
        try {
            $extra = $pdo->query('SELECT email, contact_id FROM contact_emails');
            $list = $extra instanceof PDOStatement ? $extra->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = trim((string) ($item['contact_id'] ?? ''));
                $norm = jeab_contacts_link_backfill_email((string) ($item['email'] ?? ''));
                if ($id === '' || $norm === '' || !array_key_exists($id, $companyOf)) {
                    continue;
                }
                jeab_contacts_link_backfill_index_add($email, $companyOf[$id], $norm, $id);
            }
        } catch (Throwable) {
        }
    }
    if (!function_exists('jeab_inkorg_contact_create_email_directory')) {
        require_once __DIR__ . '/inkorg_contact_create.php';
    }
    foreach (jeab_inkorg_contact_create_email_directory($pdo) as $id => $info) {
        $id = trim((string) $id);
        if ($id === '' || !array_key_exists($id, $companyOf) || !is_array($info)) {
            continue;
        }
        $shared = is_array($info['emails'] ?? null) ? $info['emails'] : [];
        foreach ($shared as $norm => $yes) {
            if ($yes && is_string($norm) && $norm !== '') {
                jeab_contacts_link_backfill_index_add($email, $companyOf[$id], $norm, $id);
            }
        }
    }

    return [$email, $phone];
}

/**
 * @param array<string, true> $cols
 * @return array<string, mixed>|null
 */
function jeab_contacts_link_backfill_json_col(array $row, array $cols, string $column): ?array
{
    if ($column === '' || !isset($cols[$column]) || !is_string($row[$column] ?? null) || $row[$column] === '') {
        return null;
    }
    $decoded = json_decode($row[$column], true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $spec
 * @param array<string, array<string, array<string, true>>> $emailMap
 * @param array<string, array<string, array<string, true>>> $phoneMap
 * @param array{list:array<string, array<string, true>>,messages:array<string, array<string, true>>,boxes:array<string, string>} $context
 * @return array{counts:array<string,mixed>,linked:list<array<string,mixed>>,uncertain:list<array<string,mixed>>,writes:list<array<string,mixed>>,proposed:list<array<string,mixed>>}
 */
function jeab_contacts_link_backfill_table(
    PDO $pdo,
    string $table,
    array $spec,
    string $idCol,
    ?string $extraJson,
    array $emailMap,
    array $phoneMap,
    bool $globalCompany,
    array $context
): array {
    $counts = [
        'present' => 0,
        'empty' => 0,
        'kept' => 0,
        'linked' => 0,
        'uncertain' => 0,
        'by_email' => 0,
        'by_phone' => 0,
        'verify_pass_count' => 0,
        'verify_fail_count' => 0,
        'co' => jeab_contacts_link_backfill_co_counts(),
    ];
    $linked = [];
    $uncertain = [];
    $writes = [];
    $proposed = [];
    $cols = jeab_contacts_sole_audit_columns($pdo, $table);
    if ($cols === [] || !isset($cols[$idCol])) {
        return ['counts' => $counts, 'linked' => $linked, 'uncertain' => $uncertain, 'writes' => $writes, 'proposed' => $proposed];
    }
    $counts['present'] = 1;
    $jsonCol = is_string($spec['json_col'] ?? null) ? (string) $spec['json_col'] : '';
    if ($jsonCol !== '' && !isset($cols[$jsonCol])) {
        $jsonCol = '';
    }
    if ($extraJson !== null && !isset($cols[$extraJson])) {
        $extraJson = null;
    }
    $select = [$idCol];
    foreach (['contact_id', 'company', 'email', 'phone', 'customer_email', 'mobile', $jsonCol, $extraJson ?? ''] as $col) {
        if ($col !== '' && isset($cols[$col]) && !in_array($col, $select, true)) {
            $select[] = $col;
        }
    }
    $safeSelect = array_map('jeab_contacts_link_backfill_ident', $select);
    $stmt = $pdo->query('SELECT ' . implode(', ', $safeSelect) . ' FROM ' . jeab_contacts_link_backfill_ident($table));
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row[$idCol] ?? ''));
        if ($id === '') {
            continue;
        }
        $json = $jsonCol !== '' ? jeab_contacts_link_backfill_json_col($row, $cols, $jsonCol) : null;
        $extra = $extraJson !== null ? jeab_contacts_link_backfill_json_col($row, $cols, $extraJson) : null;
        $have = [];
        if (isset($cols['contact_id'])) {
            $have[] = trim((string) ($row['contact_id'] ?? ''));
        }
        if (is_array($json)) {
            $have[] = jeab_contacts_link_backfill_path($json, '$.contact_id');
        }
        if (is_array($extra)) {
            $have[] = jeab_contacts_link_backfill_path($extra, '$.contact_id');
        }
        $existing = '';
        foreach ($have as $one) {
            if ($one !== '') {
                $existing = $one;
                break;
            }
        }
        $companyRaw = jeab_contacts_link_backfill_first(
            $row,
            $json,
            $cols,
            (string) ($spec['company_col'] ?? ''),
            is_array($spec['company_paths'] ?? null) ? $spec['company_paths'] : []
        );
        $company = $companyRaw === '' ? '' : jeab_contacts_link_backfill_company($companyRaw);
        $coDerived = false;
        if ($existing !== '') {
            $counts['kept']++;
            jeab_contacts_link_backfill_tally($counts, $company, 'kept');
            continue;
        }
        if ($table === 'inkorg_threads' && $company === '' && $companyRaw === '') {
            $derived = jeab_contacts_link_backfill_thread_co($id, $json, $context);
            if ((string) ($derived['co'] ?? '') !== '') {
                $company = (string) $derived['co'];
                $coDerived = true;
            }
        }
        $counts['empty']++;
        jeab_contacts_link_backfill_tally($counts, $company, 'empty');
        $open = ['table' => $table, 'id' => $id, 'co' => $company, 'co_derived' => $coDerived];
        if (($company === '' && !$globalCompany) || ($companyRaw !== '' && $company === '')) {
            $counts['uncertain']++;
            jeab_contacts_link_backfill_tally($counts, $company, 'uncertain');
            $uncertain[] = $open + ['reason' => 'no_company'];
            continue;
        }
        $global = $globalCompany && $company === '';
        $emails = [];
        if ($table === 'inkorg_contacts') {
            $emails[] = jeab_contacts_link_backfill_email((string) ($row['email'] ?? ''));
            if (is_array($json)) {
                foreach ((array) ($json['emails'] ?? []) as $one) {
                    $emails[] = jeab_contacts_link_backfill_email((string) $one);
                }
                $emails[] = jeab_contacts_link_backfill_email((string) ($json['email'] ?? ''));
            }
        } else {
            $emails[] = jeab_contacts_link_backfill_email(jeab_contacts_link_backfill_first(
                $row,
                $json,
                $cols,
                (string) ($spec['email_col'] ?? ''),
                is_array($spec['email_paths'] ?? null) ? $spec['email_paths'] : []
            ));
        }
        $emailIds = [];
        foreach ($emails as $email) {
            foreach (jeab_contacts_link_backfill_index_ids($emailMap, $company, $email, $global) as $cid => $yes) {
                if ($yes) {
                    $emailIds[(string) $cid] = true;
                }
            }
        }
        $phoneRaw = $table === 'inkorg_contacts'
            ? (string) (($row['mobile'] ?? '') !== '' ? $row['mobile'] : (is_array($json) ? ($json['mobile'] ?? $json['phone'] ?? '') : ''))
            : jeab_contacts_link_backfill_first(
                $row,
                $json,
                $cols,
                (string) ($spec['phone_col'] ?? ''),
                is_array($spec['phone_paths'] ?? null) ? $spec['phone_paths'] : []
            );
        $phoneIds = jeab_contacts_link_backfill_index_ids(
            $phoneMap,
            $company,
            jeab_contacts_link_backfill_phone($phoneRaw),
            $global
        );
        $emailKey = '';
        foreach ($emails as $oneEmail) {
            if ($oneEmail !== '') {
                $emailKey = $oneEmail;
                break;
            }
        }
        $phoneKey = jeab_contacts_link_backfill_phone($phoneRaw);
        $pick = jeab_contacts_link_backfill_pick($emailIds, $phoneIds);
        if (isset($pick['reason'])) {
            $counts['uncertain']++;
            jeab_contacts_link_backfill_tally($counts, $company, 'uncertain');
            $uncertain[] = $open + ['reason' => (string) $pick['reason']];
            continue;
        }
        $target = (string) $pick['id'];
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $target) !== 1) {
            $counts['uncertain']++;
            jeab_contacts_link_backfill_tally($counts, $company, 'uncertain');
            $uncertain[] = $open + ['reason' => 'no_match'];
            continue;
        }
        $via = (string) $pick['via'];
        $sets = [];
        $wheres = [];
        $undoJson = [];
        if (isset($cols['contact_id'])) {
            $sets['contact_id'] = $target;
            if (!array_key_exists('contact_id', $row) || $row['contact_id'] === null) {
                $wheres[] = ['sql' => 'contact_id IS NULL'];
            } else {
                $wheres[] = ['sql' => 'contact_id = ?', 'param' => (string) $row['contact_id']];
            }
        }
        foreach ([$jsonCol => $json, (string) ($extraJson ?? '') => $extra] as $col => $doc) {
            if ($col === '' || !is_string($row[$col] ?? null)) {
                continue;
            }
            if (!is_array($doc)) {
                continue;
            }
            $had = array_key_exists('contact_id', $doc);
            $doc['contact_id'] = $target;
            $encoded = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                continue;
            }
            $sets[$col] = $encoded;
            $wheres[] = ['sql' => jeab_contacts_link_backfill_ident($col) . ' = ?', 'param' => (string) $row[$col]];
            $undoJson[] = ['col' => $col, 'had' => $had];
        }
        if ($sets === []) {
            $counts['uncertain']++;
            jeab_contacts_link_backfill_tally($counts, $company, 'uncertain');
            $uncertain[] = $open + ['reason' => 'no_match'];
            continue;
        }
        $verify = jeab_contacts_link_backfill_verify($via, $target, $emailIds, $phoneIds, $emailKey, $phoneKey);
        $proposal = $open + [
            'contact_id' => $target,
            'verify' => $verify,
        ];
        $proposed[] = $proposal;
        if (!jeab_contacts_link_backfill_verify_ok($verify)) {
            $counts['uncertain']++;
            $counts['verify_fail_count']++;
            jeab_contacts_link_backfill_tally($counts, $company, 'uncertain');
            jeab_contacts_link_backfill_tally($counts, $company, 'verify_fail_count');
            $uncertain[] = $open + ['reason' => 'verify_failed'];
            continue;
        }
        $counts['linked']++;
        $counts['verify_pass_count']++;
        $counts[$via === 'phone' ? 'by_phone' : 'by_email']++;
        jeab_contacts_link_backfill_tally($counts, $company, 'linked');
        jeab_contacts_link_backfill_tally($counts, $company, 'verify_pass_count');
        $linked[] = $proposal;
        $writes[] = [
            'table' => $table,
            'id' => $id,
            'id_col' => $idCol,
            'wrote' => $target,
            'via' => $via,
            'co' => $company,
            'co_derived' => $coDerived,
            'sets' => $sets,
            'wheres' => $wheres,
            'undo_json' => $undoJson,
            'column' => isset($sets['contact_id']),
        ];
    }

    return ['counts' => $counts, 'linked' => $linked, 'uncertain' => $uncertain, 'writes' => $writes, 'proposed' => $proposed];
}

/**
 * @param array<string, mixed> $write
 */
function jeab_contacts_link_backfill_apply(PDO $pdo, array $write): bool
{
    $table = jeab_contacts_link_backfill_ident((string) $write['table']);
    $idCol = jeab_contacts_link_backfill_ident((string) $write['id_col']);
    $sets = is_array($write['sets'] ?? null) ? $write['sets'] : [];
    $wheres = is_array($write['wheres'] ?? null) ? $write['wheres'] : [];
    if ($sets === []) {
        return false;
    }
    $sqlSets = [];
    $params = [];
    foreach ($sets as $col => $value) {
        $sqlSets[] = jeab_contacts_link_backfill_ident((string) $col) . ' = ?';
        $params[] = $value;
    }
    $sqlWheres = [$idCol . ' = ?'];
    $params[] = (string) $write['id'];
    foreach ($wheres as $where) {
        if (!is_array($where) || !is_string($where['sql'] ?? null)) {
            return false;
        }
        $sqlWheres[] = $where['sql'];
        if (array_key_exists('param', $where)) {
            $params[] = $where['param'];
        }
    }
    $stmt = $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sqlSets) . ' WHERE ' . implode(' AND ', $sqlWheres));
    $stmt->execute($params);

    return $stmt->rowCount() === 1;
}

function jeab_contacts_link_backfill_marker_path(): string
{
    if (!function_exists('jeab_data_dir')) {
        return '';
    }
    $dir = rtrim(jeab_data_dir(), '/');

    return $dir === '' ? '' : $dir . '/inkorg/contacts.json.migrated';
}

function jeab_contacts_link_backfill_undo_dir(): string
{
    if (!function_exists('jeab_data_dir')) {
        return '';
    }
    $dir = rtrim(jeab_data_dir(), '/');

    return $dir === '' ? '' : $dir . '/contacts-link-backfill';
}

/**
 * @param list<array<string, mixed>> $rows
 */
function jeab_contacts_link_backfill_write_undo(string $undoId, array $rows, bool $markerCreated): string
{
    $dir = jeab_contacts_link_backfill_undo_dir();
    if ($dir === '' || preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    $payload = [
        'source' => 'contacts.link_backfill',
        'undo_id' => $undoId,
        'marker_created' => $markerCreated,
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

    return $undoId;
}

/**
 * Komplett förslagslista. Bara id och booleska kontroller.
 *
 * @param list<array<string, mixed>> $rows
 */
function jeab_contacts_link_backfill_write_proposed(array $rows): string
{
    $dir = jeab_contacts_link_backfill_undo_dir();
    if ($dir === '') {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    $id = bin2hex(random_bytes(8));
    $name = 'proposed-' . $id . '.json';
    $listed = [];
    foreach ($rows as $row) {
        $listed[] = jeab_contacts_link_backfill_emit($row, true);
    }
    $payload = [
        'source' => 'contacts.link_backfill',
        'kind' => 'proposed',
        'rows' => $listed,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    $path = $dir . '/' . $name;
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }

    return 'contacts-link-backfill/' . $name;
}

/**
 * @return array<string, mixed>|null
 */
function jeab_contacts_link_backfill_read_undo(string $undoId): ?array
{
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return null;
    }
    $dir = jeab_contacts_link_backfill_undo_dir();
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

function jeab_contacts_link_backfill_mark(string $undoId): bool
{
    $path = jeab_contacts_link_backfill_marker_path();
    if ($path === '' || is_file($path)) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode([
        'source' => 'contacts.link_backfill',
        'undo_id' => $undoId,
        'time' => function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }
    $fh = @fopen($path, 'x');
    if ($fh === false) {
        return false;
    }
    fwrite($fh, $json . "\n");
    fclose($fh);

    return is_file($path);
}

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function jeab_contacts_link_backfill_scrub(array $node): array
{
    $allow = [
        'contacts.link_backfill',
        'contacts.link_backfill_undo',
        'Fel token.',
        'Ingen lagring.',
        'Kunde inte skriva ångerfilen.',
        'Ångerfilen saknas.',
        'Körningen misslyckades.',
        'bookings',
        'reminders',
        'purchases',
        'inkorg_threads',
        'inkorg_list',
        'inkorg_contacts',
        'ambiguous_email',
        'ambiguous_phone',
        'conflict',
        'no_match',
        'no_company',
        'changed',
        'email',
        'phone',
    ];
    $out = [];
    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $out[(string) $key] = jeab_contacts_link_backfill_scrub($value);
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
        if ((string) $key === 'full_path' && preg_match('#^contacts-link-backfill/proposed-[a-f0-9]{16}\\.json$#', $value) === 1) {
            $out[(string) $key] = $value;
            continue;
        }
        if (in_array($value, $allow, true) || preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $value) === 1) {
            $out[(string) $key] = $value;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_link_backfill(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_link_backfill_dry($input);
    $full = jeab_contacts_link_backfill_full($input);
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return jeab_contacts_link_backfill_scrub([
            'ok' => false,
            'error' => 'Ingen lagring.',
            'action' => 'contacts.link_backfill',
            'dry' => $dry,
            'written' => false,
        ]);
    }
    try {
        return jeab_contacts_link_backfill_scrub(jeab_contacts_link_backfill_run($store->pdo(), $dry, $full));
    } catch (Throwable $e) {
        error_log('contacts.link_backfill: ' . $e->getMessage());

        return jeab_contacts_link_backfill_scrub([
            'ok' => false,
            'error' => 'Körningen misslyckades.',
            'action' => 'contacts.link_backfill',
            'dry' => $dry,
            'written' => false,
        ]);
    }
}

/**
 * @return array<string, mixed>
 */
function jeab_contacts_link_backfill_run(PDO $pdo, bool $dry, bool $full = false): array
{
    [$emailMap, $phoneMap] = jeab_contacts_link_backfill_contacts($pdo);
    $context = jeab_contacts_link_backfill_thread_context($pdo);
    $specs = jeab_contacts_sole_audit_specs();
    $tables = [
        'bookings' => ['id' => 'id', 'extra' => null, 'global' => false],
        'reminders' => ['id' => 'id', 'extra' => null, 'global' => false],
        'purchases' => ['id' => 'reminder_id', 'extra' => 'list_json', 'global' => false],
        'inkorg_threads' => ['id' => 'id', 'extra' => null, 'global' => false],
        'inkorg_list' => ['id' => 'id', 'extra' => null, 'global' => false],
        'inkorg_contacts' => ['id' => 'id', 'extra' => null, 'global' => true],
    ];
    $inboxSpec = [
        'table' => 'inkorg_contacts',
        'company_col' => '',
        'company_paths' => ['$.company', '$.brand'],
        'contact_col' => 'contact_id',
        'json_col' => 'row_json',
        'phone_col' => 'mobile',
        'phone_paths' => ['$.mobile', '$.phone'],
        'email_col' => 'email',
        'email_paths' => ['$.email'],
    ];
    $counts = [];
    $linked = [];
    $uncertain = [];
    $writes = [];
    $proposed = [];
    foreach ($tables as $name => $meta) {
        $spec = $name === 'inkorg_contacts' ? $inboxSpec : ($specs[$name] ?? null);
        if (!is_array($spec)) {
            continue;
        }
        $part = jeab_contacts_link_backfill_table(
            $pdo,
            $name,
            $spec,
            (string) $meta['id'],
            is_string($meta['extra']) ? $meta['extra'] : null,
            $emailMap,
            $phoneMap,
            (bool) $meta['global'],
            $context
        );
        $counts[$name] = $part['counts'];
        foreach ($part['linked'] as $row) {
            $linked[] = $row;
        }
        foreach ($part['uncertain'] as $row) {
            $uncertain[] = $row;
        }
        foreach ($part['writes'] as $row) {
            $writes[] = $row;
        }
        foreach ($part['proposed'] as $row) {
            $proposed[] = $row;
        }
    }
    $sort = static function (array $a, array $b): int {
        return [(string) ($a['table'] ?? ''), (string) ($a['id'] ?? '')] <=> [(string) ($b['table'] ?? ''), (string) ($b['id'] ?? '')];
    };
    usort($linked, $sort);
    usort($uncertain, $sort);
    usort($proposed, $sort);
    $verifyPass = 0;
    $verifyFail = 0;
    foreach ($counts as $row) {
        if (!is_array($row)) {
            continue;
        }
        $verifyPass += (int) ($row['verify_pass_count'] ?? 0);
        $verifyFail += (int) ($row['verify_fail_count'] ?? 0);
    }
    $samples = [];
    foreach (array_slice($linked, 0, 20) as $row) {
        $samples[] = jeab_contacts_link_backfill_emit($row, $dry);
    }
    $listed = [];
    foreach (array_slice($uncertain, 0, 1000) as $row) {
        $listed[] = jeab_contacts_link_backfill_emit($row, false);
    }
    $base = [
        'ok' => true,
        'action' => 'contacts.link_backfill',
        'dry' => $dry,
        'written' => false,
        'undo_id' => '',
        'inkorg_contacts_migrated' => is_file(jeab_contacts_link_backfill_marker_path()),
        'inkorg_contacts_unmatched' => (int) ($counts['inkorg_contacts']['uncertain'] ?? 0),
        'tables' => $counts,
        'samples' => $samples,
        'uncertain' => $listed,
        'uncertain_more' => max(0, count($uncertain) - count($listed)),
        'verify_pass_count' => $verifyPass,
        'verify_fail_count' => $verifyFail,
    ];
    if ($full) {
        $base['full_path'] = jeab_contacts_link_backfill_write_proposed($proposed);
    }
    if ($dry) {
        return $base;
    }
    $undoId = bin2hex(random_bytes(8));
    $undoRows = [];
    foreach ($writes as $write) {
        $undoRows[] = [
            'table' => (string) $write['table'],
            'id' => (string) $write['id'],
            'id_col' => (string) $write['id_col'],
            'wrote' => (string) $write['wrote'],
            'column' => !empty($write['column']),
            'json' => $write['undo_json'],
        ];
    }
    $markerBefore = is_file(jeab_contacts_link_backfill_marker_path());
    jeab_contacts_link_backfill_write_undo($undoId, $undoRows, false);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $applied = [];
        foreach ($writes as $write) {
            if (jeab_contacts_link_backfill_apply($pdo, $write)) {
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
        $dir = jeab_contacts_link_backfill_undo_dir();
        if ($dir !== '') {
            @unlink($dir . '/' . $undoId . '.json');
        }
        throw $e;
    }
    $undoRows = [];
    $appliedCounts = $counts;
    foreach ($appliedCounts as $name => $row) {
        $appliedCounts[$name]['linked'] = 0;
        $appliedCounts[$name]['by_email'] = 0;
        $appliedCounts[$name]['by_phone'] = 0;
        foreach (['jarfalla', 'malmo', 'none'] as $coKey) {
            if (isset($appliedCounts[$name]['co'][$coKey]['linked'])) {
                $appliedCounts[$name]['co'][$coKey]['linked'] = 0;
            }
        }
    }
    $appliedSamples = [];
    foreach ($applied as $write) {
        $name = (string) $write['table'];
        if (isset($appliedCounts[$name])) {
            $appliedCounts[$name]['linked']++;
            $viaKey = (string) ($write['via'] ?? '') === 'phone' ? 'by_phone' : 'by_email';
            $appliedCounts[$name][$viaKey]++;
            $coKey = jeab_contacts_link_backfill_co_key((string) ($write['co'] ?? ''));
            if (isset($appliedCounts[$name]['co'][$coKey]['linked'])) {
                $appliedCounts[$name]['co'][$coKey]['linked']++;
            }
        }
        $undoRows[] = [
            'table' => $name,
            'id' => (string) $write['id'],
            'id_col' => (string) $write['id_col'],
            'wrote' => (string) $write['wrote'],
            'column' => !empty($write['column']),
            'json' => $write['undo_json'],
        ];
        if (count($appliedSamples) < 20) {
            $appliedSamples[] = jeab_contacts_link_backfill_emit([
                'table' => $name,
                'id' => (string) $write['id'],
                'contact_id' => (string) $write['wrote'],
                'co' => (string) ($write['co'] ?? ''),
                'co_derived' => !empty($write['co_derived']),
            ], false);
        }
    }
    foreach ($writes as $write) {
        $hit = false;
        foreach ($applied as $done) {
            if ((string) $done['table'] === (string) $write['table'] && (string) $done['id'] === (string) $write['id']) {
                $hit = true;
                break;
            }
        }
        if ($hit) {
            continue;
        }
        $name = (string) $write['table'];
        if (isset($appliedCounts[$name])) {
            $appliedCounts[$name]['uncertain']++;
            $coKey = jeab_contacts_link_backfill_co_key((string) ($write['co'] ?? ''));
            if (isset($appliedCounts[$name]['co'][$coKey]['uncertain'])) {
                $appliedCounts[$name]['co'][$coKey]['uncertain']++;
            }
        }
        $listed[] = jeab_contacts_link_backfill_emit([
            'table' => $name,
            'id' => (string) $write['id'],
            'reason' => 'changed',
            'co' => (string) ($write['co'] ?? ''),
            'co_derived' => !empty($write['co_derived']),
        ], false);
    }
    $inboxPresent = (int) ($appliedCounts['inkorg_contacts']['present'] ?? 0) === 1;
    $inboxUnmatched = (int) ($appliedCounts['inkorg_contacts']['uncertain'] ?? 0);
    $wantMarker = !$markerBefore && $inboxPresent && $inboxUnmatched === 0;
    jeab_contacts_link_backfill_write_undo($undoId, $undoRows, $wantMarker);
    $markerCreated = $wantMarker && jeab_contacts_link_backfill_mark($undoId);
    if ($wantMarker && !$markerCreated) {
        jeab_contacts_link_backfill_write_undo($undoId, $undoRows, false);
    }
    $base['written'] = true;
    $base['dry'] = false;
    $base['undo_id'] = $undoId;
    $base['tables'] = $appliedCounts;
    $base['samples'] = $appliedSamples;
    $base['uncertain'] = array_slice($listed, 0, 1000);
    $base['inkorg_contacts_unmatched'] = (int) ($appliedCounts['inkorg_contacts']['uncertain'] ?? 0);
    $base['inkorg_contacts_migrated'] = is_file(jeab_contacts_link_backfill_marker_path());

    return $base;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_link_backfill_undo(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_link_backfill_dry($input);
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return jeab_contacts_link_backfill_scrub([
            'ok' => false,
            'error' => 'Ångerfilen saknas.',
            'action' => 'contacts.link_backfill_undo',
            'dry' => $dry,
            'written' => false,
        ]);
    }
    $file = jeab_contacts_link_backfill_read_undo($undoId);
    if (!is_array($file) || (string) ($file['source'] ?? '') !== 'contacts.link_backfill') {
        return jeab_contacts_link_backfill_scrub([
            'ok' => false,
            'error' => 'Ångerfilen saknas.',
            'action' => 'contacts.link_backfill_undo',
            'dry' => $dry,
            'written' => false,
            'undo_id' => $undoId,
        ]);
    }
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return jeab_contacts_link_backfill_scrub([
            'ok' => false,
            'error' => 'Ingen lagring.',
            'action' => 'contacts.link_backfill_undo',
            'dry' => $dry,
            'written' => false,
            'undo_id' => $undoId,
        ]);
    }
    try {
        $result = jeab_contacts_link_backfill_undo_run($store->pdo(), $file, $dry);
    } catch (Throwable $e) {
        error_log('contacts.link_backfill_undo: ' . $e->getMessage());
        $result = [
            'ok' => false,
            'error' => 'Körningen misslyckades.',
            'action' => 'contacts.link_backfill_undo',
            'dry' => $dry,
            'written' => false,
            'undo_id' => $undoId,
        ];
    }

    return jeab_contacts_link_backfill_scrub($result);
}

/**
 * @param array<string, mixed> $file
 * @return array<string, mixed>
 */
function jeab_contacts_link_backfill_undo_run(PDO $pdo, array $file, bool $dry): array
{
    $rows = is_array($file['rows'] ?? null) ? $file['rows'] : [];
    $plan = 0;
    $restored = 0;
    $skipped = 0;
    $work = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $table = (string) ($row['table'] ?? '');
        $allowed = ['bookings', 'reminders', 'purchases', 'inkorg_threads', 'inkorg_list', 'inkorg_contacts'];
        if (!in_array($table, $allowed, true)) {
            continue;
        }
        $plan++;
        $work[] = $row;
    }
    if ($dry) {
        return [
            'ok' => true,
            'action' => 'contacts.link_backfill_undo',
            'dry' => true,
            'written' => false,
            'undo_id' => (string) ($file['undo_id'] ?? ''),
            'rows' => $plan,
            'restored' => 0,
            'skipped' => 0,
            'marker_removed' => false,
        ];
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($work as $row) {
            if (jeab_contacts_link_backfill_restore($pdo, $row)) {
                $restored++;
            } else {
                $skipped++;
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
    $removed = false;
    if (!empty($file['marker_created'])) {
        $removed = jeab_contacts_link_backfill_unmark((string) ($file['undo_id'] ?? ''));
    }

    return [
        'ok' => true,
        'action' => 'contacts.link_backfill_undo',
        'dry' => false,
        'written' => true,
        'undo_id' => (string) ($file['undo_id'] ?? ''),
        'rows' => $plan,
        'restored' => $restored,
        'skipped' => $skipped,
        'marker_removed' => $removed,
        'inkorg_contacts_migrated' => is_file(jeab_contacts_link_backfill_marker_path()),
    ];
}

/**
 * @param array<string, mixed> $row
 */
function jeab_contacts_link_backfill_restore(PDO $pdo, array $row): bool
{
    $table = jeab_contacts_link_backfill_ident((string) $row['table']);
    $idCol = (string) ($row['id_col'] ?? 'id');
    if (!in_array($idCol, ['id', 'reminder_id'], true)) {
        return false;
    }
    $idCol = jeab_contacts_link_backfill_ident($idCol);
    $id = (string) ($row['id'] ?? '');
    $wrote = (string) ($row['wrote'] ?? '');
    if ($id === '' || $wrote === '') {
        return false;
    }
    $cols = jeab_contacts_sole_audit_columns($pdo, $table);
    if (!isset($cols[$idCol])) {
        return false;
    }
    $select = [$idCol];
    if (!empty($row['column']) && isset($cols['contact_id'])) {
        $select[] = 'contact_id';
    }
    $json = is_array($row['json'] ?? null) ? $row['json'] : [];
    foreach ($json as $part) {
        if (is_array($part) && isset($cols[(string) ($part['col'] ?? '')])) {
            $select[] = (string) $part['col'];
        }
    }
    $select = array_values(array_unique($select));
    $safe = array_map('jeab_contacts_link_backfill_ident', $select);
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $safe) . ' FROM ' . $table . ' WHERE ' . $idCol . ' = ?');
    $stmt->execute([$id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($current)) {
        return false;
    }
    $sets = [];
    $params = [];
    $wheres = [$idCol . ' = ?'];
    $whereParams = [$id];
    $changed = false;
    if (!empty($row['column']) && isset($cols['contact_id']) && array_key_exists('contact_id', $current)) {
        if (trim((string) $current['contact_id']) !== $wrote) {
            return false;
        }
        $sets[] = 'contact_id = ?';
        $params[] = '';
        if ($current['contact_id'] === null) {
            $wheres[] = 'contact_id IS NULL';
        } else {
            $wheres[] = 'contact_id = ?';
            $whereParams[] = (string) $current['contact_id'];
        }
        $changed = true;
    }
    foreach ($json as $part) {
        if (!is_array($part)) {
            continue;
        }
        $col = (string) ($part['col'] ?? '');
        if (!in_array($col, ['payload', 'row_json', 'list_json'], true) || !isset($cols[$col]) || !is_string($current[$col] ?? null)) {
            continue;
        }
        $doc = json_decode($current[$col], true);
        if (!is_array($doc) || trim((string) ($doc['contact_id'] ?? '')) !== $wrote) {
            return false;
        }
        if (!empty($part['had'])) {
            $doc['contact_id'] = '';
        } else {
            unset($doc['contact_id']);
        }
        $encoded = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return false;
        }
        $sets[] = jeab_contacts_link_backfill_ident($col) . ' = ?';
        $params[] = $encoded;
        $wheres[] = jeab_contacts_link_backfill_ident($col) . ' = ?';
        $whereParams[] = $current[$col];
        $changed = true;
    }
    if (!$changed || $sets === []) {
        return false;
    }
    $upd = $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $wheres));
    $upd->execute(array_merge($params, $whereParams));

    return $upd->rowCount() === 1;
}

function jeab_contacts_link_backfill_unmark(string $undoId): bool
{
    $path = jeab_contacts_link_backfill_marker_path();
    if ($path === '' || !is_file($path) || preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return false;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || (string) ($decoded['source'] ?? '') !== 'contacts.link_backfill' || (string) ($decoded['undo_id'] ?? '') !== $undoId) {
        return false;
    }

    return @unlink($path);
}
