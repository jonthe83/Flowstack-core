<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Hook kontakter.address_import.
 * dry=1 är standard. dry måste vara 0 för att skriva.
 * Skriver bara apply-rader: tom gata, tom ort och tomt postnummer fylls.
 * Telefon sätts bara när kontakten saknar telefon.
 * Övrigt får en rad. Evidence och personnummer skrivs inte dit och loggas inte.
 */

const JEAB_ADDRESS_IMPORT_MAX = 500;

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_address_import(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.'];
    }
    $run = static function () use ($input, $store): array {
        return jeab_kontakter_address_import_inner($input, $store);
    };
    if (function_exists('jeab_kontakt_quiet_call')) {
        $result = jeab_kontakt_quiet_call($run);
    } else {
        $result = $run();
    }
    if (!is_array($result)) {
        return ['ok' => false, 'error' => 'Ingen lagring.'];
    }
    return jeab_kontakter_address_import_mask_value($result);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_address_import_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_kontakter_address_import($input);
    $result = jeab_kontakter_address_import_mask_value($result);
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_address_import_inner(array $input, JeabStore $store): array
{
    $dry = jeab_kontakt_dry($input);
    $min = jeab_kontakter_address_import_min($input);
    if ($min === null) {
        return ['ok' => false, 'error' => 'min_confidence ska vara ett tal mellan 0 och 1.', 'dry' => $dry];
    }
    $rows = jeab_kontakter_address_import_rows($input);
    if ($rows === null) {
        return ['ok' => false, 'error' => 'rows ska vara en lista.', 'dry' => $dry];
    }
    if (count($rows) > JEAB_ADDRESS_IMPORT_MAX) {
        return ['ok' => false, 'error' => 'Högst 500 rader.', 'dry' => $dry];
    }

    $contacts = jeab_kontakter_load();
    $plan = jeab_kontakter_address_import_classify($rows, $contacts, $min);
    $applied = 0;
    $backup = null;
    if (!$dry && $plan['writes'] !== []) {
        $backup = jeab_kontakter_backup_snapshot($store);
        if ($backup === '') {
            return ['ok' => false, 'error' => 'Kunde inte spara säkerhetskopia.', 'dry' => false];
        }
        try {
            $applied = (int) jeab_kontakter_mutate(static function (array &$live) use ($plan): int {
                return jeab_kontakter_address_import_write($live, $plan['writes']);
            });
        } catch (Throwable $e) {
            unset($e);
            return ['ok' => false, 'error' => 'Kunde inte spara kontakten.', 'dry' => false, 'backup_file' => $backup];
        }
    }

    return jeab_kontakter_address_import_response($dry, $min, $plan['buckets'], $applied, $backup);
}

/**
 * @param array<string, mixed> $input
 */
function jeab_kontakter_address_import_min(array $input): ?float
{
    if (!array_key_exists('min_confidence', $input)) {
        return 0.8;
    }
    $value = jeab_kontakter_address_import_confidence($input['min_confidence']);
    return $value;
}

function jeab_kontakter_address_import_confidence(mixed $value): ?float
{
    if (is_int($value) || is_float($value)) {
        $number = (float) $value;
    } elseif (is_string($value)) {
        $text = str_replace(',', '.', trim($value));
        if (preg_match('/^\d+(?:\.\d+)?$/', $text) !== 1) {
            return null;
        }
        $number = (float) $text;
    } else {
        return null;
    }
    if (!is_finite($number) || $number < 0 || $number > 1) {
        return null;
    }
    return round($number, 4);
}

/**
 * @param array<string, mixed> $input
 * @return list<mixed>|null
 */
function jeab_kontakter_address_import_rows(array $input): ?array
{
    $rows = $input['rows'] ?? null;
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
 * En rad hamnar i exakt en hink.
 * invalid, sedan low_confidence, sedan contact_missing, already_has_address, duplicate_address, apply.
 *
 * @param list<mixed> $rows
 * @param array<string, array<string, mixed>> $contacts
 * @return array{buckets: array<string, list<array<string, mixed>>>, writes: list<array<string, mixed>>}
 */
function jeab_kontakter_address_import_classify(array $rows, array $contacts, float $min): array
{
    $buckets = jeab_kontakter_address_import_empty_buckets();
    $parsed = [];
    foreach ($rows as $row) {
        $parsed[] = jeab_kontakter_address_import_parse($row, $min);
    }

    $existing = [];
    foreach ($contacts as $id => $contact) {
        if (!is_array($contact)) {
            continue;
        }
        $cid = trim((string) ($contact['id'] ?? $id));
        $key = jeab_kontakter_address_import_contact_key($contact);
        if ($cid === '' || $key === '') {
            continue;
        }
        $existing[$key][$cid] = true;
    }

    $claims = [];
    foreach ($parsed as $item) {
        $key = (string) ($item['key'] ?? '');
        $id = (string) ($item['contact_id'] ?? '');
        if ($key === '' || $id === '' || ($item['bucket'] ?? '') === 'invalid') {
            continue;
        }
        $claims[$key][$id] = true;
    }

    $writes = [];
    $seen = [];
    foreach ($parsed as $item) {
        $bucket = (string) ($item['bucket'] ?? '');
        $id = (string) ($item['contact_id'] ?? '');
        $confidence = $item['confidence'];
        $confidence = is_float($confidence) || is_int($confidence) ? round((float) $confidence, 4) : null;
        if ($bucket === 'invalid' || $bucket === 'low_confidence') {
            $buckets[$bucket][] = jeab_kontakter_address_import_item($id, (string) $item['reason'], $confidence);
            continue;
        }
        $contact = ($id !== '' && isset($contacts[$id]) && is_array($contacts[$id])) ? $contacts[$id] : null;
        if ($contact === null) {
            $buckets['contact_missing'][] = jeab_kontakter_address_import_item($id, 'kontakten saknas', $confidence);
            continue;
        }
        $held = jeab_kontakter_address_import_held($contact, $item);
        if ($held !== null) {
            $buckets['already_has_address'][] = jeab_kontakter_address_import_item($id, $held, $confidence);
            continue;
        }
        $key = (string) ($item['key'] ?? '');
        $others = [];
        foreach ($existing[$key] ?? [] as $other => $_) {
            if ((string) $other !== $id) {
                $others[(string) $other] = true;
            }
        }
        foreach ($claims[$key] ?? [] as $other => $_) {
            if ((string) $other !== $id) {
                $others[(string) $other] = true;
            }
        }
        if ($others !== []) {
            $ids = array_keys($others);
            sort($ids, SORT_STRING);
            $buckets['duplicate_address'][] = jeab_kontakter_address_import_item($id, 'samma adress på annan kontakt', $confidence, $ids);
            continue;
        }
        if (isset($seen[$id])) {
            $buckets['already_has_address'][] = jeab_kontakter_address_import_item($id, 'kontakten är med tidigare i anropet', $confidence);
            continue;
        }
        $seen[$id] = true;
        $buckets['apply'][] = jeab_kontakter_address_import_item($id, 'tom adress', $confidence);
        $write = $item['write'] ?? null;
        if (is_array($write)) {
            $writes[] = $write;
        }
    }

    return ['buckets' => $buckets, 'writes' => $writes];
}

/**
 * @return array<string, list<array<string, mixed>>>
 */
function jeab_kontakter_address_import_empty_buckets(): array
{
    return [
        'apply' => [],
        'low_confidence' => [],
        'duplicate_address' => [],
        'already_has_address' => [],
        'contact_missing' => [],
        'invalid' => [],
    ];
}

/**
 * @return array{bucket:string,reason:string,contact_id:string,confidence:?float,key:string,write:?array<string,mixed>}
 */
function jeab_kontakter_address_import_parse(mixed $row, float $min): array
{
    $blank = [
        'bucket' => 'invalid',
        'reason' => 'raden är inte ett objekt',
        'contact_id' => '',
        'confidence' => null,
        'key' => '',
        'write' => null,
    ];
    if (!is_array($row)) {
        return $blank;
    }
    $id = jeab_kontakter_address_import_text($row['contact_id'] ?? null, 80);
    if ($id === null) {
        $blank['reason'] = 'contact_id saknas';
        return $blank;
    }
    if (preg_match('/[\x00-\x1F]/', $id) === 1) {
        $blank['contact_id'] = $id;
        $blank['reason'] = 'contact_id saknas';
        return $blank;
    }
    $blank['contact_id'] = $id;
    if (!array_key_exists('confidence', $row)) {
        $blank['reason'] = 'confidence saknas';
        return $blank;
    }
    $confidence = jeab_kontakter_address_import_confidence($row['confidence']);
    if ($confidence === null) {
        $blank['reason'] = 'confidence är ogiltig';
        return $blank;
    }
    $blank['confidence'] = $confidence;
    $proposal = jeab_kontakter_address_import_proposal($row);
    if ($confidence + 1.0e-9 < $min) {
        return [
            'bucket' => 'low_confidence',
            'reason' => 'under min_confidence',
            'contact_id' => $id,
            'confidence' => $confidence,
            'key' => (string) ($proposal['key'] ?? ''),
            'write' => null,
        ];
    }
    if (!empty($proposal['error'])) {
        $blank['reason'] = (string) $proposal['error'];
        return $blank;
    }
    $write = is_array($proposal['write'] ?? null) ? $proposal['write'] : null;
    if ($write === null) {
        $blank['reason'] = 'gatan saknas';
        return $blank;
    }
    $write['id'] = $id;
    return [
        'bucket' => '',
        'reason' => '',
        'contact_id' => $id,
        'confidence' => $confidence,
        'key' => (string) $proposal['key'],
        'write' => $write,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array{error:string,key:string,write:?array<string,mixed>}
 */
function jeab_kontakter_address_import_proposal(array $row): array
{
    $fail = static function (string $reason): array {
        return ['error' => $reason, 'key' => '', 'write' => null];
    };
    $street = jeab_kontakter_address_import_text($row['street'] ?? null, 200);
    $number = jeab_kontakter_address_import_text($row['number'] ?? null, 40);
    $cityRaw = jeab_kontakter_address_import_text($row['city'] ?? null, 120);
    $postalRaw = jeab_kontakter_address_import_text($row['postal'] ?? null, 20);
    $source = jeab_kontakter_address_import_text($row['source'] ?? null, 80);
    if ($street === null) {
        return $fail('gatan saknas');
    }
    if ($number === null) {
        return $fail('numret saknas');
    }
    if ($cityRaw === null) {
        return $fail('orten saknas');
    }
    if ($postalRaw === null) {
        return $fail('postnumret ska vara fem siffror');
    }
    if ($source === null) {
        return $fail('source saknas');
    }
    if (!is_string($row['evidence'] ?? null) || trim($row['evidence']) === '') {
        return $fail('evidence saknas');
    }
    if (strlen($row['evidence']) > 4000) {
        return $fail('fältet är för långt');
    }
    $street = jeab_kontakt_strip_country($street);
    $number = jeab_kontakt_strip_country($number);
    $city = jeab_kontakt_city_name($cityRaw);
    if ($street === '' || jeab_kontakter_address_import_is_country($street)) {
        return $fail('gatan saknas');
    }
    if ($number === '' || jeab_kontakter_address_import_is_country($number)) {
        return $fail('numret saknas');
    }
    if ($city === '' || jeab_kontakter_address_import_is_country($city)) {
        return $fail('orten saknas');
    }
    $postal = jeab_kontakter_address_import_postal($postalRaw);
    if ($postal === '') {
        return $fail('postnumret ska vara fem siffror');
    }
    $line = jeab_kontakter_address_import_line($street, $number);
    if ($line === '' || jeab_kontakter_address_import_is_country($line)) {
        return $fail('gatan saknas');
    }
    $parts = jeab_kontakt_address_parts($line);
    $identity = jeab_kontakt_address_identity($line, $city);
    if (($parts['street_key'] ?? '') === '' || ($identity['key'] ?? '') === '') {
        return $fail('gatan saknas');
    }
    $source = jeab_kontakter_address_import_source($source);
    if ($source === '') {
        return $fail('source saknas');
    }
    $phone = '';
    if (array_key_exists('phone', $row) && $row['phone'] !== null && $row['phone'] !== '') {
        $phoneText = jeab_kontakter_address_import_text($row['phone'], 40);
        if ($phoneText === null) {
            return $fail('telefonen är ogiltig');
        }
        $phone = jeab_kontakt_phone($phoneText);
        if ($phone !== '' && function_exists('jeab_kontakt_phone_blocked') && jeab_kontakt_phone_blocked($phone)) {
            $phone = '';
        }
    }
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $now, $m) === 1 ? $m[0] : substr($now, 0, 10);
    return [
        'error' => '',
        'key' => (string) $identity['key'],
        'write' => [
            'line' => $line,
            'street_key' => jeab_kontakt_street_key($line),
            'postal' => $postal,
            'city' => $city,
            'phone' => $phone,
            'note' => 'Adress från ' . $source . ' (' . $date . ')',
        ],
    ];
}

function jeab_kontakter_address_import_text(mixed $value, int $max): ?string
{
    if (is_int($value) || is_float($value)) {
        if (is_float($value) && !is_finite($value)) {
            return null;
        }
        $value = (string) $value;
    }
    if (!is_string($value)) {
        return null;
    }
    $text = trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\n", "\t"], ' ', $value)) ?? $value);
    if ($text === '') {
        return null;
    }
    if (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') > $max : strlen($text) > $max) {
        return null;
    }
    return $text;
}

function jeab_kontakter_address_import_is_country(string $value): bool
{
    $fold = function_exists('jeab_kontakt_fold_text') ? jeab_kontakt_fold_text($value) : strtolower($value);
    return in_array($fold, ['sverige', 'sweden', 'suede', 'suède'], true);
}

function jeab_kontakter_address_import_postal(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^\d{5}$/', $raw) === 1) {
        return $raw;
    }
    if (preg_match('/^\d{3}\s\d{2}$/', $raw) === 1) {
        return str_replace(' ', '', $raw);
    }
    return '';
}

function jeab_kontakter_address_import_line(string $street, string $number): string
{
    $street = trim($street);
    $number = trim($number);
    if ($street === '' || $number === '') {
        return '';
    }
    $foldStreet = function_exists('jeab_kontakt_fold_text') ? jeab_kontakt_fold_text($street) : strtolower($street);
    $foldNumber = function_exists('jeab_kontakt_fold_text') ? jeab_kontakt_fold_text($number) : strtolower($number);
    if ($foldNumber !== '' && (str_ends_with($foldStreet, ' ' . $foldNumber) || $foldStreet === $foldNumber)) {
        return $street;
    }
    return trim($street . ' ' . $number);
}

function jeab_kontakter_address_import_source(string $source): string
{
    $source = preg_replace_callback(
        '/(?<!\d)(?:\d{6}|\d{8})[-+ ]?\d{4}(?!\d)/',
        static function (array $match): string {
            $norm = function_exists('jeab_norm_pnr') ? jeab_norm_pnr((string) $match[0]) : '';
            return $norm !== '' ? '' : (string) $match[0];
        },
        $source
    ) ?? $source;
    $source = trim(preg_replace('/\s+/u', ' ', $source) ?? $source);
    if ($source === '' || jeab_kontakter_address_import_is_country($source)) {
        return '';
    }
    if (function_exists('mb_substr')) {
        $source = mb_substr($source, 0, 80, 'UTF-8');
    } else {
        $source = substr($source, 0, 80);
    }
    return trim($source);
}

/** @param array<string, mixed> $contact */
function jeab_kontakter_address_import_contact_key(array $contact): string
{
    $street = trim((string) ($contact['street'] ?? ''));
    $city = function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name((string) ($contact['city'] ?? '')) : trim((string) ($contact['city'] ?? ''));
    if ($street === '' || $city === '') {
        return '';
    }
    if (function_exists('jeab_kontakt_street_key') && jeab_kontakt_street_key($street) === '') {
        return '';
    }
    $identity = jeab_kontakt_address_identity($street, $city);
    return (string) ($identity['key'] ?? '');
}

/**
 * @param array<string, mixed> $contact
 * @param array<string, mixed> $item
 */
function jeab_kontakter_address_import_held(array $contact, array $item): ?string
{
    if (jeab_kontakter_address_import_contact_key($contact) !== '') {
        return 'kontakten har redan adress';
    }
    $write = is_array($item['write'] ?? null) ? $item['write'] : [];
    $line = (string) ($write['line'] ?? '');
    $city = (string) ($write['city'] ?? '');
    $postal = (string) ($write['postal'] ?? '');
    $street = trim((string) ($contact['street'] ?? ''));
    $haveCity = function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name((string) ($contact['city'] ?? '')) : trim((string) ($contact['city'] ?? ''));
    $rawPostal = trim((string) ($contact['postal_code'] ?? ''));
    $havePostal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal($rawPostal) : $rawPostal;
    if ($street !== '') {
        $have = jeab_kontakt_address_parts($street);
        $want = jeab_kontakt_address_parts($line);
        if (($have['street_key'] ?? '') === '' || ($have['street_key'] ?? '') !== ($want['street_key'] ?? '')) {
            return 'gatan är redan ifylld';
        }
    }
    if ($haveCity !== '' && jeab_kontakt_city_key($haveCity) !== jeab_kontakt_city_key($city)) {
        return 'orten är redan ifylld';
    }
    if ($rawPostal !== '' && ($havePostal === '' || $havePostal !== $postal)) {
        return 'postnumret är redan ifyllt';
    }
    return null;
}

/**
 * @param list<string>|null $others
 * @return array<string, mixed>
 */
function jeab_kontakter_address_import_item(string $id, string $reason, ?float $confidence, ?array $others = null): array
{
    $item = [
        'contact_id' => $id,
        'reason' => $reason,
        'confidence' => $confidence,
    ];
    if ($others !== null) {
        $item['other_contact_ids'] = array_values($others);
    }
    return $item;
}

/** @param array<string, mixed> $contact */
function jeab_kontakter_address_import_current_phone(array $contact): string
{
    $top = jeab_kontakt_phone((string) ($contact['phone'] ?? ''));
    if ($top !== '') {
        return $top;
    }
    if (function_exists('jeab_kontakt_row_phone')) {
        $flagged = jeab_kontakt_row_phone($contact);
        if ($flagged !== '') {
            return $flagged;
        }
    }
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $phone = jeab_kontakt_phone((string) ($owner['phone'] ?? ''));
        if ($phone !== '') {
            return $phone;
        }
    }
    return '';
}

/**
 * @param array<string, array<string, mixed>> $contacts
 * @param list<array<string, mixed>> $writes
 */
function jeab_kontakter_address_import_write(array &$contacts, array $writes): int
{
    $applied = 0;
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    foreach ($writes as $write) {
        $id = trim((string) ($write['id'] ?? ''));
        if ($id === '' || !isset($contacts[$id]) || !is_array($contacts[$id])) {
            continue;
        }
        $row = $contacts[$id];
        if (jeab_kontakter_address_import_held($row, ['write' => $write]) !== null) {
            continue;
        }
        $line = trim((string) ($write['line'] ?? ''));
        $city = trim((string) ($write['city'] ?? ''));
        $postal = trim((string) ($write['postal'] ?? ''));
        if ($line === '' || $city === '' || $postal === '') {
            continue;
        }
        if (jeab_kontakter_address_import_is_country($line) || jeab_kontakter_address_import_is_country($city) || jeab_kontakter_address_import_is_country($postal)) {
            continue;
        }
        if (trim((string) ($row['street'] ?? '')) === '') {
            $row['street'] = $line;
            $row['street_key'] = (string) ($write['street_key'] ?? '');
            if ($row['street_key'] === '') {
                $row['street_key'] = jeab_kontakt_street_key($line);
            }
        }
        if (trim((string) ($row['city'] ?? '')) === '') {
            $row['city'] = $city;
        }
        if (trim((string) ($row['postal_code'] ?? '')) === '') {
            $row['postal_code'] = $postal;
        }
        $phone = trim((string) ($write['phone'] ?? ''));
        if ($phone !== '' && jeab_kontakter_address_import_current_phone($row) === '') {
            $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];
            $slot = null;
            foreach ($owners as $index => $owner) {
                if (is_array($owner) && !empty($owner['contact'])) {
                    $slot = $index;
                    break;
                }
            }
            if ($slot === null && isset($owners[0]) && is_array($owners[0])) {
                $slot = 0;
            }
            if ($slot !== null) {
                $owners[$slot]['phone'] = $phone;
                $row['owners'] = $owners;
            }
        }
        $note = trim((string) ($write['note'] ?? ''));
        if ($note !== '' && !jeab_kontakter_address_import_is_country($note)) {
            $have = function_exists('jeab_kontakt_ovrigt') ? jeab_kontakt_ovrigt($row) : trim((string) ($row['ovrigt'] ?? ''));
            if ($have === '') {
                $row['ovrigt'] = $note;
            } elseif (!str_contains($have, $note)) {
                $row['ovrigt'] = $have . "\n" . $note;
            }
            if (function_exists('mb_substr')) {
                $row['ovrigt'] = mb_substr((string) $row['ovrigt'], 0, 4000, 'UTF-8');
            } else {
                $row['ovrigt'] = substr((string) $row['ovrigt'], 0, 4000);
            }
        }
        $row['updated_at'] = $now;
        $contacts[$id] = $row;
        $applied++;
    }
    return $applied;
}

/**
 * @param array<string, list<array<string, mixed>>> $buckets
 * @return array<string, mixed>
 */
function jeab_kontakter_address_import_response(bool $dry, float $min, array $buckets, int $applied, ?string $backup): array
{
    $counts = [];
    foreach (array_keys(jeab_kontakter_address_import_empty_buckets()) as $name) {
        $counts[$name] = count($buckets[$name] ?? []);
    }
    $out = [
        'ok' => true,
        'dry' => $dry,
        'min_confidence' => round($min, 4),
        'counts' => $counts,
        'applied' => $dry ? 0 : $applied,
        'backup_file' => $backup,
    ];
    foreach ($buckets as $name => $items) {
        $out[$name] = $items;
    }
    return jeab_kontakter_address_import_mask_value($out);
}

function jeab_kontakter_address_import_mask_text(string $text): string
{
    $masked = preg_replace_callback(
        '/(?<!\d)(?:\d{6}|\d{8})[-+ ]?\d{4}(?!\d)/',
        static function (array $match): string {
            $raw = (string) $match[0];
            $norm = function_exists('jeab_norm_pnr') ? jeab_norm_pnr($raw) : '';
            if ($norm === '') {
                return $raw;
            }
            return function_exists('jeab_kontakt_pnr_mask') ? jeab_kontakt_pnr_mask($norm) : '******-****';
        },
        $text
    );
    return is_string($masked) ? $masked : $text;
}

function jeab_kontakter_address_import_mask_value(mixed $value): mixed
{
    if (is_string($value)) {
        return jeab_kontakter_address_import_mask_text($value);
    }
    if (!is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $key => $item) {
        $out[$key] = jeab_kontakter_address_import_mask_value($item);
    }
    return $out;
}
