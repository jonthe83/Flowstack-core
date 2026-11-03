<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Högst två sekundära adresser per kontakt (email2, email3).
 * Normaliseras med trim och gemener. En adress tillhör högst en kontakt,
 * som primär eller sekundär. Utskick läser bara den primära adressen.
 */

function jeab_kontakt_secondary_norm(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (function_exists('jeab_kontakt_email')) {
        return jeab_kontakt_email($raw);
    }
    $norm = function_exists('jeab_norm_email') ? jeab_norm_email($raw) : strtolower($raw);

    return str_contains($norm, '@') ? $norm : '';
}

/**
 * @param array<string, mixed> $row
 * @return array{0:string,1:string}
 */
function jeab_kontakt_secondary_pair(array $row): array
{
    return [
        jeab_kontakt_secondary_norm((string) ($row['email2'] ?? '')),
        jeab_kontakt_secondary_norm((string) ($row['email3'] ?? '')),
    ];
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_kontakt_secondary_list(array $row): array
{
    $out = [];
    foreach (jeab_kontakt_secondary_pair($row) as $email) {
        if ($email !== '' && !isset($out[$email])) {
            $out[$email] = true;
        }
    }

    return array_keys($out);
}

/**
 * Primära ägaradresser plus sekundära. Dubblettkollen använder listan.
 * Dedupe-planen som slår ihop kontakter gör det inte.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_kontakt_dup_emails(array $row): array
{
    $out = [];
    $primary = function_exists('jeab_kontakt_row_emails') ? jeab_kontakt_row_emails($row) : [];
    foreach (array_merge($primary, jeab_kontakt_secondary_list($row)) as $email) {
        $email = jeab_kontakt_secondary_norm((string) $email);
        if ($email !== '') {
            $out[$email] = true;
        }
    }

    return array_keys($out);
}

/**
 * @param array<string, array<string, mixed>> $contacts
 */
function jeab_kontakt_secondary_holder(array $contacts, string $email, string $exceptId = ''): string
{
    $email = jeab_kontakt_secondary_norm($email);
    if ($email === '') {
        return '';
    }
    foreach ($contacts as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = trim((string) ($row['id'] ?? $id));
        if ($cid === '' || $cid === $exceptId || (string) $id === $exceptId) {
            continue;
        }
        if (in_array($email, jeab_kontakt_secondary_list($row), true)) {
            return $cid;
        }
    }

    return '';
}

/**
 * @param array<string, array<string, mixed>> $contacts
 */
function jeab_kontakt_primary_holder(array $contacts, string $email, string $exceptId = ''): string
{
    $email = jeab_kontakt_secondary_norm($email);
    if ($email === '' || !function_exists('jeab_kontakt_row_emails')) {
        return '';
    }
    foreach ($contacts as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = trim((string) ($row['id'] ?? $id));
        if ($cid === '' || $cid === $exceptId || (string) $id === $exceptId) {
            continue;
        }
        if (in_array($email, jeab_kontakt_row_emails($row), true)) {
            return $cid;
        }
    }

    return '';
}

/**
 * Offert, faktura och övriga utskick. Bara kontaktpersonens primära adress.
 * Ett fryst utskick (frozen + to/email) lämnas som det sparades.
 *
 * @param array<string, mixed> $contact
 * @param array<string, mixed> $snapshot
 */
function jeab_kontakt_outgoing_email(array $contact): string
{
    return function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($contact) : '';
}

/** @param array<string, mixed> $contact @param array<string, mixed> $snapshot */
function jeab_quote_invoice_recipient(array $contact, array $snapshot = []): string
{
    if (!empty($snapshot['frozen'])) {
        $to = (string) ($snapshot['to'] ?? $snapshot['email'] ?? '');

        return jeab_kontakt_secondary_norm($to);
    }

    return jeab_kontakt_outgoing_email($contact);
}

/**
 * Sekundär e-post från formuläret. Saknade nycklar lämnar de sparade värdena.
 * En upptagen adress avvisas. Kontakter slås inte ihop.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed>|null $existing
 * @param list<array<string, mixed>> $owners
 * @param array<string, array<string, mixed>> $contacts
 * @return array{0:string,1:string}
 */
function jeab_kontakt_secondary_from_input(array $input, ?array $existing, string $primary, array $owners, array $contacts, string $selfId): array
{
    $posted = array_key_exists('email2', $input) || array_key_exists('email3', $input);
    $have = is_array($existing) ? jeab_kontakt_secondary_pair($existing) : ['', ''];
    $primary = jeab_kontakt_secondary_norm($primary);
    if ($primary !== '') {
        if (in_array($primary, $have, true)) {
            throw new InvalidArgumentException('E-post är samma som en adress som redan finns på kontakten.');
        }
        $takenPrimary = jeab_kontakt_secondary_holder($contacts, $primary, $selfId);
        if ($takenPrimary !== '') {
            throw new InvalidArgumentException('E-postadressen används redan av en annan kontakt.');
        }
    }
    if (!$posted) {
        return $have;
    }
    $labels = ['email2' => 'Sekundär e-post 1', 'email3' => 'Sekundär e-post 2'];
    $pair = ['', ''];
    foreach (['email2', 'email3'] as $i => $key) {
        if (!array_key_exists($key, $input)) {
            $pair[$i] = $have[$i];
            continue;
        }
        $raw = trim((string) $input[$key]);
        if ($raw === '') {
            $pair[$i] = '';
            continue;
        }
        $norm = jeab_kontakt_secondary_norm($raw);
        if ($norm === '') {
            throw new InvalidArgumentException($labels[$key] . ' är inte en giltig adress.');
        }
        $pair[$i] = $norm;
    }
    $own = [];
    if ($primary !== '') {
        $own[$primary] = true;
    }
    foreach ($owners as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $ownerEmail = jeab_kontakt_secondary_norm((string) ($owner['email'] ?? ''));
        if ($ownerEmail !== '') {
            $own[$ownerEmail] = true;
        }
    }
    if ($pair[0] !== '' && $pair[1] !== '' && $pair[0] === $pair[1]) {
        throw new InvalidArgumentException('Sekundär e-post 2 är samma som sekundär e-post 1.');
    }
    foreach ([0, 1] as $i) {
        $email = $pair[$i];
        if ($email === '') {
            continue;
        }
        $label = $i === 0 ? 'Sekundär e-post 1' : 'Sekundär e-post 2';
        if (isset($own[$email])) {
            throw new InvalidArgumentException($label . ' är samma som en adress som redan finns på kontakten.');
        }
        $takenSecondary = jeab_kontakt_secondary_holder($contacts, $email, $selfId);
        $takenPrimary = jeab_kontakt_primary_holder($contacts, $email, $selfId);
        if ($takenSecondary !== '' || $takenPrimary !== '') {
            throw new InvalidArgumentException('E-postadressen används redan av en annan kontakt.');
        }
        if (function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($email) && $email !== $have[$i]) {
            throw new InvalidArgumentException('E-postadressen tillhör inte en kund.');
        }
        $own[$email] = true;
    }

    return $pair;
}

function jeab_contact_secondary_columns_ready(PDO $pdo): bool
{
    $gen = (int) ($GLOBALS['_JEAB_CONTACT_SECONDARY_GEN'] ?? 0);
    $key = spl_object_id($pdo) . ':' . $gen;
    static $seen = [];
    if (array_key_exists($key, $seen)) {
        return $seen[$key];
    }
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $a = $pdo->query("SHOW COLUMNS FROM contacts LIKE 'email2'");
            $b = $pdo->query("SHOW COLUMNS FROM contacts LIKE 'email3'");
            $ok = ($a instanceof PDOStatement && is_array($a->fetch(PDO::FETCH_ASSOC)))
                && ($b instanceof PDOStatement && is_array($b->fetch(PDO::FETCH_ASSOC)));
        } else {
            $names = [];
            $info = $pdo->query('PRAGMA table_info(contacts)');
            foreach ($info instanceof PDOStatement ? $info->fetchAll(PDO::FETCH_ASSOC) : [] as $col) {
                if (is_array($col)) {
                    $names[] = (string) ($col['name'] ?? '');
                }
            }
            $ok = in_array('email2', $names, true) && in_array('email3', $names, true);
        }
    } catch (Throwable) {
        $ok = false;
    }
    $seen[$key] = $ok;

    return $ok;
}

/** @param array<string, mixed> $row */
function jeab_contact_secondary_write_columns(PDO $pdo, string $id, array $row): void
{
    $id = trim($id);
    if ($id === '' || !jeab_contact_secondary_columns_ready($pdo)) {
        return;
    }
    $pair = jeab_kontakt_secondary_pair($row);
    try {
        $pdo->prepare('UPDATE contacts SET email2 = ?, email3 = ? WHERE id = ?')->execute([
            mb_substr($pair[0], 0, 191),
            mb_substr($pair[1], 0, 191),
            $id,
        ]);
    } catch (Throwable) {
    }
}

/**
 * Lägger sekundära adresser i mejlindexet. Slår inte ihop kontakter.
 *
 * @param array<string, array<string, true>> $byEmail
 * @param array<string, true> $contactIds
 */
function jeab_contact_secondary_index(PDO $pdo, array &$byEmail, array $contactIds): void
{
    $add = static function (string $id, string $raw) use (&$byEmail, $contactIds): void {
        if ($id === '' || !isset($contactIds[$id])) {
            return;
        }
        $email = function_exists('jeab_norm_email') ? jeab_norm_email($raw) : strtolower(trim($raw));
        if ($email === '' || !str_contains($email, '@')) {
            return;
        }
        $byEmail[$email][$id] = true;
    };
    if (jeab_contact_secondary_columns_ready($pdo)) {
        try {
            $rows = $pdo->query('SELECT id, email2, email3 FROM contacts');
            foreach ($rows instanceof PDOStatement ? $rows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (string) ($row['id'] ?? '');
                $add($id, (string) ($row['email2'] ?? ''));
                $add($id, (string) ($row['email3'] ?? ''));
            }
        } catch (Throwable) {
        }
    }
    try {
        $rows = $pdo->query('SELECT id, row_json FROM contacts');
        $list = $rows instanceof PDOStatement ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        return;
    }
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $json = json_decode((string) ($row['row_json'] ?? ''), true);
        if (!is_array($json)) {
            continue;
        }
        $add($id, (string) ($json['email2'] ?? ''));
        $add($id, (string) ($json['email3'] ?? ''));
    }
}
