<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/contact_store.php';
require_once __DIR__ . '/hook_scoped_tokens.php';

/**
 * Hook kontakter.import_new.
 * dry=1 är standard och skriver inget. dry=0 tar en tidsstämplad kopia och skapar nya kontakter.
 * Inkorgstoken eller en scoped agent-token. Svaret har antal och stickprov, aldrig kundfält.
 *
 * POST hook.php
 * Authorization: Bearer <inkorgstoken>
 * {"action":"kontakter.import_new","dry":1,"bolag":"jarfalla","rows":[{"namn":"","mejl":"","telefon":"","gata":"","postnr":"","ort":""}]}
 * bolag, company eller co krävs. Varje ny rad får det kontoret. Utan bolag skrivs inget.
 */

const JEAB_IMPORT_NEW_MAX = 2000;
const JEAB_IMPORT_NEW_SAMPLES = 20;

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_import_new(array $input, ?JeabContactStore $store = null): array
{
    $store = $store ?? jeab_contact_store();
    $run = static function () use ($input, $store): array {
        return jeab_kontakter_import_new_inner($input, $store);
    };
    if (function_exists('jeab_kontakt_quiet_call')) {
        $result = jeab_kontakt_quiet_call($run);
    } else {
        $result = $run();
    }
    return is_array($result) ? $result : ['ok' => false, 'error' => 'Ingen lagring.'];
}

function jeab_kontakter_import_new_authorized(): bool
{
    if (function_exists('jeab_inkorg_hook_authorized') && jeab_inkorg_hook_authorized()) {
        return true;
    }
    return jeab_hook_scoped_authorized('kontakter.import_new');
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_import_new_dispatch(array $input): array
{
    if (!jeab_kontakter_import_new_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_kontakter_import_new($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_import_new_inner(array $input, JeabContactStore $store): array
{
    $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : jeab_kontakter_import_new_dry($input);
    $rows = jeab_kontakter_import_new_rows($input);
    if ($rows === null) {
        return ['ok' => false, 'error' => 'rows ska vara en lista.', 'dry' => $dry];
    }
    if (count($rows) > JEAB_IMPORT_NEW_MAX) {
        return ['ok' => false, 'error' => 'Högst 2000 rader.', 'dry' => $dry];
    }
    $office = jeab_kontakter_import_new_office($input);
    if ($office['error'] !== '') {
        return ['ok' => false, 'error' => $office['error'], 'dry' => $dry];
    }

    try {
        $contacts = $store->all();
    } catch (Throwable $e) {
        unset($e);
        return ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => $dry];
    }
    $plan = jeab_kontakter_import_new_plan($rows, $contacts, $office['office']);
    $created = [];
    $backup = null;
    if (!$dry && $plan['creates'] !== []) {
        try {
            $backup = $store->backup();
        } catch (Throwable $e) {
            unset($e);
            $backup = '';
        }
        if ($backup === '') {
            return ['ok' => false, 'error' => 'Kunde inte spara säkerhetskopia.', 'dry' => false];
        }
        try {
            $created = $store->insertMany($plan['creates']);
        } catch (Throwable $e) {
            unset($e);
            return ['ok' => false, 'error' => 'Kunde inte spara kontakten.', 'dry' => false, 'backup_file' => $backup];
        }
    }

    return jeab_kontakter_import_new_response($dry, $plan, $created, $backup);
}

function jeab_kontakter_import_new_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];
    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/**
 * @param array<string, mixed> $input
 * @return list<mixed>|null
 */
function jeab_kontakter_import_new_rows(array $input): ?array
{
    $rows = $input['rows'] ?? $input['kontakter'] ?? null;
    if (is_string($rows)) {
        $decoded = json_decode($rows, true);
        $rows = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($rows) || !array_is_list($rows)) {
        return null;
    }
    return $rows;
}

/**
 * @param list<mixed> $rows
 * @param array<string, array<string, mixed>> $contacts
 * @return array{creates:list<array<string,mixed>>,create_indexes:list<int>,duplicates:list<array<string,mixed>>,invalid:list<array<string,mixed>>}
 */
function jeab_kontakter_import_new_plan(array $rows, array $contacts, string $office): array
{
    $index = jeab_kontakter_import_new_index($contacts);
    $creates = [];
    $createIndexes = [];
    $duplicates = [];
    $invalid = [];
    foreach ($rows as $indexNo => $row) {
        $parsed = jeab_kontakter_import_new_parse($row, $office);
        $at = (int) $indexNo;
        if ($parsed['error'] !== '') {
            $invalid[] = ['index' => $at, 'reason' => $parsed['error']];
            continue;
        }
        $hit = jeab_kontakter_import_new_hit($index, $parsed['keys']);
        if ($hit !== null) {
            $duplicates[] = [
                'index' => $at,
                'matched_id' => $hit['id'],
                'matched_key' => $hit['key'],
            ];
            continue;
        }
        $contact = $parsed['contact'];
        if (!is_array($contact)) {
            $invalid[] = ['index' => $at, 'reason' => 'namn saknas'];
            continue;
        }
        $contact['company'] = $office;
        $creates[] = $contact;
        $createIndexes[] = $at;
        jeab_kontakter_import_new_remember($index, (string) $contact['id'], $parsed['keys'], 'batch:' . $at);
    }
    return [
        'creates' => $creates,
        'create_indexes' => $createIndexes,
        'duplicates' => $duplicates,
        'invalid' => $invalid,
    ];
}

/**
 * @param array<string, array<string, mixed>> $contacts
 * @return array{email:array<string,string>,phone:array<string,string>,address:array<string,string>}
 */
function jeab_kontakter_import_new_index(array $contacts): array
{
    $index = ['email' => [], 'phone' => [], 'address' => []];
    foreach ($contacts as $id => $contact) {
        if (!is_array($contact)) {
            continue;
        }
        $cid = trim((string) ($contact['id'] ?? $id));
        if ($cid === '') {
            continue;
        }
        jeab_kontakter_import_new_remember($index, $cid, jeab_kontakter_import_new_keys_from_contact($contact), $cid);
    }
    return $index;
}

/**
 * @param array{email:array<string,string>,phone:array<string,string>,address:array<string,string>} $index
 * @param array{email:string,phone:string,address:string} $keys
 */
function jeab_kontakter_import_new_remember(array &$index, string $id, array $keys, string $label): void
{
    foreach (['email', 'phone', 'address'] as $kind) {
        $value = $keys[$kind];
        if ($value !== '' && !isset($index[$kind][$value])) {
            $index[$kind][$value] = $label !== '' ? $label : $id;
        }
    }
}

/**
 * @param array{email:array<string,string>,phone:array<string,string>,address:array<string,string>} $index
 * @param array{email:string,phone:string,address:string} $keys
 * @return array{id:string,key:string}|null
 */
function jeab_kontakter_import_new_hit(array $index, array $keys): ?array
{
    $names = ['email' => 'mejl', 'phone' => 'telefon', 'address' => 'adress'];
    foreach ($names as $kind => $label) {
        $value = $keys[$kind];
        if ($value !== '' && isset($index[$kind][$value])) {
            return ['id' => $index[$kind][$value], 'key' => $label];
        }
    }
    return null;
}

/**
/**
 * Bolag på hooken. company, bolag och co är samma fält. Ogiltigt värde ekas inte.
 *
 * @param array<string, mixed> $input
 * @return array{error:string,office:string}
 */
function jeab_kontakter_import_new_office(array $input): array
{
    $raw = $input['company'] ?? $input['bolag'] ?? $input['co'] ?? null;
    if (is_array($raw) || is_object($raw)) {
        return ['error' => 'Bolaget är ogiltigt.', 'office' => ''];
    }
    $text = trim((string) $raw);
    if ($text === '') {
        return ['error' => 'Bolag saknas.', 'office' => ''];
    }
    $office = function_exists('jeab_address_company') ? jeab_address_company($text) : '';
    if ($office === '') {
        return ['error' => 'Bolaget är ogiltigt.', 'office' => ''];
    }

    return ['error' => '', 'office' => $office];
}

/**
 * @return array{error:string,keys:array{email:string,phone:string,address:string},contact:?array<string,mixed>}
 */
function jeab_kontakter_import_new_parse(mixed $row, string $office = ''): array
{
    $emptyKeys = ['email' => '', 'phone' => '', 'address' => ''];
    $fail = static function (string $reason) use ($emptyKeys): array {
        return ['error' => $reason, 'keys' => $emptyKeys, 'contact' => null];
    };
    if (!is_array($row)) {
        return $fail('raden är inte ett objekt');
    }
    $name = jeab_kontakter_import_new_field($row['namn'] ?? null, 200);
    $emailRaw = jeab_kontakter_import_new_field($row['mejl'] ?? null, 200);
    $phoneRaw = jeab_kontakter_import_new_field($row['telefon'] ?? null, 40);
    $street = jeab_kontakter_import_new_field($row['gata'] ?? null, 200);
    $postal = jeab_kontakter_import_new_field($row['postnr'] ?? null, 20);
    $city = jeab_kontakter_import_new_field($row['ort'] ?? null, 120);
    foreach ([$name, $emailRaw, $phoneRaw, $street, $postal, $city] as $field) {
        if ($field['error'] !== '') {
            return $fail($field['error']);
        }
    }
    if ($name['value'] === '') {
        return $fail('namn saknas');
    }
    $email = jeab_kontakt_email($emailRaw['value']);
    if ($emailRaw['value'] !== '' && $email === '') {
        return $fail('mejl är ogiltig');
    }
    if ($email !== '' && function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($email)) {
        $email = '';
    }
    $phone = jeab_kontakt_phone($phoneRaw['value']);
    if ($phone !== '' && function_exists('jeab_kontakt_phone_blocked') && jeab_kontakt_phone_blocked($phone)) {
        $phone = '';
    }
    $postalNorm = function_exists('jeab_norm_postal') ? jeab_norm_postal($postal['value']) : jeab_kontakt_postal($postal['value']);
    $cityNorm = jeab_kontakt_city_name($city['value']);
    $keys = [
        'email' => $email,
        'phone' => $phone,
        'address' => jeab_kontakter_import_new_address_key($street['value'], $postalNorm, $cityNorm),
    ];
    $hint = [
        'name' => $name['value'],
        'email' => $email,
        'phone' => $phone,
        'street' => $street['value'],
        'street_key' => function_exists('jeab_kontakt_street_key') ? jeab_kontakt_street_key($street['value']) : '',
        'postal_code' => $postalNorm,
        'city' => $cityNorm,
        'kind' => 'person',
        'source' => 'fortnox',
    ];
    if ($office !== '') {
        $hint['company'] = $office;
    }
    $contact = jeab_kontakt_blank($hint);
    $contact['typ'] = 'Privatperson';
    return ['error' => '', 'keys' => $keys, 'contact' => $contact];
}

/**
 * @param array<string, mixed> $contact
 * @return array{email:string,phone:string,address:string}
 */
function jeab_kontakter_import_new_keys_from_contact(array $contact): array
{
    $email = '';
    if (function_exists('jeab_kontakt_row_emails')) {
        foreach (jeab_kontakt_row_emails($contact) as $candidate) {
            $candidate = jeab_kontakt_email((string) $candidate);
            if ($candidate === '' || (function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($candidate))) {
                continue;
            }
            $email = $candidate;
            break;
        }
    }
    if ($email === '') {
        $email = jeab_kontakt_email((string) (function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($contact) : ''));
        if ($email !== '' && function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($email)) {
            $email = '';
        }
    }
    $phone = '';
    foreach (jeab_kontakter_import_new_contact_phones($contact) as $candidate) {
        if ($candidate !== '' && (!function_exists('jeab_kontakt_phone_blocked') || !jeab_kontakt_phone_blocked($candidate))) {
            $phone = $candidate;
            break;
        }
    }
    return [
        'email' => $email,
        'phone' => $phone,
        'address' => jeab_kontakter_import_new_address_key(
            (string) ($contact['street'] ?? ''),
            (string) ($contact['postal_code'] ?? ''),
            (string) ($contact['city'] ?? '')
        ),
    ];
}

/** @param array<string, mixed> $contact @return list<string> */
function jeab_kontakter_import_new_contact_phones(array $contact): array
{
    $out = [];
    $add = static function (string $raw) use (&$out): void {
        $phone = jeab_kontakt_phone($raw);
        if ($phone !== '' && !isset($out[$phone])) {
            $out[$phone] = true;
        }
    };
    if (function_exists('jeab_kontakt_row_phone')) {
        $add(jeab_kontakt_row_phone($contact));
    }
    $add((string) ($contact['phone'] ?? ''));
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $add((string) ($owner['phone'] ?? ''));
        }
    }
    return array_keys($out);
}

function jeab_kontakter_import_new_address_key(string $street, string $postal, string $city): string
{
    $street = jeab_kontakter_import_new_strip($street);
    $postal = jeab_kontakter_import_new_strip($postal);
    $city = jeab_kontakt_city_name(jeab_kontakter_import_new_strip($city));
    if ($street === '' || !function_exists('jeab_norm_street_key')) {
        return '';
    }
    return jeab_norm_street_key($street, $postal, $city);
}

/**
 * @return array{error:string,value:string}
 */
function jeab_kontakter_import_new_field(mixed $value, int $max): array
{
    if ($value === null || $value === '') {
        return ['error' => '', 'value' => ''];
    }
    if (is_int($value) || is_float($value)) {
        if (is_float($value) && !is_finite($value)) {
            return ['error' => 'fältet är ogiltigt', 'value' => ''];
        }
        $value = (string) $value;
    }
    if (!is_string($value)) {
        return ['error' => 'fältet är ogiltigt', 'value' => ''];
    }
    $text = jeab_kontakter_import_new_strip($value);
    if ($text === '') {
        return ['error' => '', 'value' => ''];
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length > $max) {
        return ['error' => 'fältet är för långt', 'value' => ''];
    }
    return ['error' => '', 'value' => $text];
}

function jeab_kontakter_import_new_strip(string $value): string
{
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if (function_exists('jeab_kontakt_strip_country')) {
        $value = jeab_kontakt_strip_country($value);
    }
    $value = preg_replace('/\b(?:sverige|sweden|suede|suède)\b/iu', ' ', $value) ?? $value;
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    $value = trim($value, " ,");
    return $value;
}

/**
 * @param array{creates:list<array<string,mixed>>,create_indexes:list<int>,duplicates:list<array<string,mixed>>,invalid:list<array<string,mixed>>} $plan
 * @param list<string> $created
 * @return array<string, mixed>
 */
function jeab_kontakter_import_new_response(bool $dry, array $plan, array $created, ?string $backup): array
{
    $cap = static function (array $items): array {
        return array_slice(array_values($items), 0, JEAB_IMPORT_NEW_SAMPLES);
    };
    $would = [];
    foreach ($plan['create_indexes'] as $at) {
        $would[] = ['index' => (int) $at];
    }
    $out = [
        'ok' => true,
        'dry' => $dry,
        'counts' => [
            'would_create' => count($plan['creates']),
            'duplicates' => count($plan['duplicates']),
            'invalid' => count($plan['invalid']),
        ],
        'samples' => [
            'would_create' => $cap($would),
            'duplicates' => $cap($plan['duplicates']),
            'invalid' => $cap($plan['invalid']),
        ],
    ];
    if (!$dry) {
        $createdIds = array_values($created);
        $byIndex = [];
        foreach ($plan['create_indexes'] as $i => $at) {
            if (isset($createdIds[$i])) {
                $byIndex[(int) $at] = (string) $createdIds[$i];
            }
        }
        $duplicates = [];
        foreach ($plan['duplicates'] as $dup) {
            $matched = (string) ($dup['matched_id'] ?? '');
            if (preg_match('/^batch:(\d+)$/', $matched, $m) === 1 && isset($byIndex[(int) $m[1]])) {
                $dup['matched_id'] = $byIndex[(int) $m[1]];
            }
            $duplicates[] = $dup;
        }
        $out['samples']['duplicates'] = $cap($duplicates);
        $out['created_ids'] = $createdIds;
        $out['counts']['created'] = count($createdIds);
        $out['backup_file'] = $backup;
    }
    return $out;
}
