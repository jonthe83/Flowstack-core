<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * STEG4_CUTOVER, CONTACTS_SOLE, CONTACTS_STRICT, LEGACY_JSON_WRITES,
 * INBOX_CONTACTS_FROM_THREADS, CONTACTS_NAME_CANDIDATE_DISPLAY,
 * CONTACTS_AUTO_MERGE och OFFER_SEND_ONE_ENABLED (av som standard).
 * flags.set skriver CONTACTS_AUTO_MERGE utan confirm=SAM_YES.
 * Ordning: $GLOBALS, miljövariabel, privat/flags.json utanför webbroten.
 * Saknas källa: STEG4_CUTOVER och CONTACTS_SOLE på, övriga av.
 * Filen vinner över den standarden. Rollback är att sätta flaggan i filen.
 * Filen skrivs bara via flags.set (temp + rename i privat/).
 */

/** @return list<string> */
function jeab_flag_names(): array
{
    return ['STEG4_CUTOVER', 'CONTACTS_SOLE', 'CONTACTS_STRICT', 'LEGACY_JSON_WRITES', 'INBOX_CONTACTS_FROM_THREADS', 'CONTACTS_NAME_CANDIDATE_DISPLAY', 'CONTACTS_AUTO_MERGE', 'OFFER_SEND_ONE_ENABLED'];
}

function jeab_flag_known(string $name): bool
{
    return in_array($name, jeab_flag_names(), true);
}

function jeab_flag_global_key(string $name): string
{
    return '_JEAB_' . $name;
}

function jeab_flag_env_raw(string $name): ?string
{
    if (!jeab_flag_known($name)) {
        return null;
    }
    $env = getenv($name);
    if ($name === 'STEG4_CUTOVER') {
        return ($env === '1' || $env === '0') ? $env : null;
    }
    if ($env === false || $env === '') {
        $env = array_key_exists($name, $_ENV) ? (string) $_ENV[$name] : '';
    }
    $env = trim((string) $env);

    return $env === '' ? null : $env;
}

function jeab_flag_env_bool(string $name): ?bool
{
    if ($name === 'STEG4_CUTOVER') {
        $env = getenv('STEG4_CUTOVER');
        if ($env === '1') {
            return true;
        }
        if ($env === '0') {
            return false;
        }

        return null;
    }
    if ($name === 'CONTACTS_SOLE' || $name === 'CONTACTS_STRICT') {
        $env = getenv($name);
        if ($env === false || $env === '') {
            $env = (string) ($_ENV[$name] ?? '');
        }
        $env = strtolower(trim((string) $env));
        if ($env === '1' || $env === 'true') {
            return true;
        }
        if ($env === '0' || $env === 'false') {
            return false;
        }

        return null;
    }
    if ($name === 'LEGACY_JSON_WRITES' || $name === 'INBOX_CONTACTS_FROM_THREADS' || $name === 'CONTACTS_NAME_CANDIDATE_DISPLAY' || $name === 'CONTACTS_AUTO_MERGE' || $name === 'OFFER_SEND_ONE_ENABLED') {
        $raw = jeab_flag_env_raw($name);
        if ($raw === null) {
            return null;
        }
        $env = strtolower($raw);
        if ($env === '1' || $env === 'true' || $env === 'on') {
            return true;
        }
        if ($env === '0' || $env === 'false' || $env === 'off') {
            return false;
        }

        return null;
    }

    return null;
}

function jeab_flag_parse_stored(string $name, mixed $raw): ?bool
{
    if (!jeab_flag_known($name)) {
        return null;
    }
    if (is_bool($raw)) {
        return $raw;
    }
    if (is_int($raw)) {
        if ($raw === 1) {
            return true;
        }
        if ($raw === 0) {
            return false;
        }

        return null;
    }
    if (!is_string($raw)) {
        return null;
    }
    $text = strtolower(trim($raw));
    if ($name === 'STEG4_CUTOVER') {
        if ($text === '1') {
            return true;
        }
        if ($text === '0') {
            return false;
        }

        return null;
    }
    if ($name === 'CONTACTS_SOLE' || $name === 'CONTACTS_STRICT') {
        if ($text === '1' || $text === 'true') {
            return true;
        }
        if ($text === '0' || $text === 'false') {
            return false;
        }

        return null;
    }
    if ($text === '1' || $text === 'true' || $text === 'on') {
        return true;
    }
    if ($text === '0' || $text === 'false' || $text === 'off') {
        return false;
    }

    return null;
}

function jeab_flags_dir(): ?string
{
    if (!function_exists('jeab_outside_privat_dir') || !function_exists('jeab_app_web_root')) {
        return null;
    }
    $dir = rtrim(str_replace('\\', '/', jeab_outside_privat_dir()), '/');
    if ($dir === '' || str_contains($dir, "\0")) {
        return null;
    }
    $web = rtrim(str_replace('\\', '/', jeab_app_web_root()), '/');
    if ($web !== '' && ($dir === $web || str_starts_with($dir, $web . '/'))) {
        return null;
    }

    return $dir;
}

function jeab_flags_file_path(): ?string
{
    $dir = jeab_flags_dir();

    return $dir === null ? null : $dir . '/flags.json';
}

function jeab_flags_audit_path(): ?string
{
    $dir = jeab_flags_dir();

    return $dir === null ? null : $dir . '/flags-audit.log';
}

function jeab_flags_realpath_inside(string $path): bool
{
    $dir = jeab_flags_dir();
    if ($dir === null || $path === '' || str_contains($path, "\0")) {
        return false;
    }
    $realDir = realpath($dir);
    $realPath = realpath($path);
    if ($realDir === false || $realPath === false) {
        return false;
    }
    $realDir = rtrim(str_replace('\\', '/', $realDir), '/');
    $realPath = rtrim(str_replace('\\', '/', $realPath), '/');
    $web = realpath(jeab_app_web_root());
    if (is_string($web) && $web !== '') {
        $web = rtrim(str_replace('\\', '/', $web), '/');
        if ($realPath === $web || str_starts_with($realPath, $web . '/')) {
            return false;
        }
    }

    return $realPath === $realDir || str_starts_with($realPath, $realDir . '/');
}

/** @return array<string, int> */
function jeab_flags_read_disk(): array
{
    $path = jeab_flags_file_path();
    if ($path === null || !is_file($path) || is_link($path)) {
        return [];
    }
    if (is_dir(dirname($path)) && !jeab_flags_realpath_inside($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
        return [];
    }
    $out = [];
    foreach (jeab_flag_names() as $name) {
        if (!array_key_exists($name, $decoded)) {
            continue;
        }
        $parsed = jeab_flag_parse_stored($name, $decoded[$name]);
        if ($parsed !== null) {
            $out[$name] = $parsed ? 1 : 0;
        }
    }

    return $out;
}

function jeab_flag_file_bool(string $name): ?bool
{
    if (!jeab_flag_known($name)) {
        return null;
    }
    $disk = jeab_flags_read_disk();
    if (!array_key_exists($name, $disk)) {
        return null;
    }

    return $disk[$name] === 1;
}

/**
 * @return array{value:bool,source:string}
 */
function jeab_flag_resolve(string $name): array
{
    if (!jeab_flag_known($name)) {
        return ['value' => false, 'source' => 'default'];
    }
    $key = jeab_flag_global_key($name);
    if (array_key_exists($key, $GLOBALS)) {
        return ['value' => (bool) $GLOBALS[$key], 'source' => 'globals'];
    }
    $env = jeab_flag_env_bool($name);
    if ($env !== null) {
        return ['value' => $env, 'source' => 'env'];
    }
    $file = jeab_flag_file_bool($name);
    if ($file !== null) {
        return ['value' => $file, 'source' => 'file'];
    }

    return [
        'value' => $name === 'STEG4_CUTOVER' || $name === 'CONTACTS_SOLE',
        'source' => 'default',
    ];
}

function jeab_flag(string $name): bool
{
    return jeab_flag_resolve($name)['value'];
}

function jeab_flag_explicit(string $name): ?bool
{
    $resolved = jeab_flag_resolve($name);
    if ($resolved['source'] === 'default') {
        return null;
    }

    return $resolved['value'];
}

/**
 * Effektivt läge som appkoden använder, plus källan.
 *
 * @return array{value:int,source:string}
 */
function jeab_flag_effective(string $name): array
{
    $resolved = jeab_flag_resolve($name);
    if ($name === 'CONTACTS_SOLE' && $resolved['source'] === 'default' && function_exists('jeab_contacts_sole_stored')) {
        $stored = jeab_contacts_sole_stored();
        if ($stored === '1' || $stored === '0') {
            return ['value' => $stored === '1' ? 1 : 0, 'source' => 'settings'];
        }
    }

    return ['value' => $resolved['value'] ? 1 : 0, 'source' => $resolved['source']];
}

function jeab_flags_wants_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_flags_set_value(mixed $value): ?int
{
    if ($value === 1 || $value === '1') {
        return 1;
    }
    if ($value === 0 || $value === '0') {
        return 0;
    }

    return null;
}

function jeab_flags_writable_dir(): ?string
{
    $dir = jeab_flags_dir();
    if ($dir === null) {
        return null;
    }
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
    }
    if (is_link($dir) || !is_dir($dir) || !is_writable($dir)) {
        return null;
    }
    $real = realpath($dir);
    if ($real === false) {
        return null;
    }
    $real = rtrim(str_replace('\\', '/', $real), '/');
    $web = realpath(jeab_app_web_root());
    if (is_string($web) && $web !== '') {
        $web = rtrim(str_replace('\\', '/', $web), '/');
        if ($real === $web || str_starts_with($real, $web . '/')) {
            return null;
        }
    }
    $logical = realpath($dir);
    if ($logical === false || rtrim(str_replace('\\', '/', $logical), '/') !== $real) {
        return null;
    }

    return $real;
}

/**
 * @param array<string, int> $flags
 */
function jeab_flags_write_atomic(array $flags): bool
{
    $dir = jeab_flags_writable_dir();
    if ($dir === null) {
        return false;
    }
    $clean = [];
    foreach (jeab_flag_names() as $name) {
        if (!array_key_exists($name, $flags)) {
            continue;
        }
        $value = $flags[$name];
        if ($value !== 0 && $value !== 1) {
            return false;
        }
        $clean[$name] = $value;
    }
    $dest = $dir . '/flags.json';
    $tmp = $dir . '/flags.json.' . bin2hex(random_bytes(8)) . '.tmp';
    if (!str_starts_with($dest, $dir . '/') || !str_starts_with($tmp, $dir . '/')) {
        return false;
    }
    if (is_link($dest)) {
        return false;
    }
    $prevFile = null;
    if (is_file($dest)) {
        $decodedPrev = json_decode((string) file_get_contents($dest), true);
        $prevFile = is_array($decodedPrev) ? $decodedPrev : null;
    }
    if (array_key_exists('BOOKING_MOVE_MAIL_TEST_IDS', $flags) && function_exists('jeab_booking_move_mail_parse_ids')) {
        $keepIds = jeab_booking_move_mail_parse_ids($flags['BOOKING_MOVE_MAIL_TEST_IDS']);
        if ($keepIds !== []) {
            $clean['BOOKING_MOVE_MAIL_TEST_IDS'] = $keepIds;
        }
    } elseif (is_array($prevFile) && array_key_exists('BOOKING_MOVE_MAIL_TEST_IDS', $prevFile) && function_exists('jeab_booking_move_mail_parse_ids')) {
        $keepIds = jeab_booking_move_mail_parse_ids($prevFile['BOOKING_MOVE_MAIL_TEST_IDS']);
        if ($keepIds !== []) {
            $clean['BOOKING_MOVE_MAIL_TEST_IDS'] = $keepIds;
        }
    }
    $moveMail = null;
    if (array_key_exists('BOOKING_MOVE_MAIL', $flags)) {
        $moveMail = $flags['BOOKING_MOVE_MAIL'];
    } elseif (is_array($prevFile) && array_key_exists('BOOKING_MOVE_MAIL', $prevFile)) {
        $moveMail = $prevFile['BOOKING_MOVE_MAIL'];
    }
    if ($moveMail === true) {
        $moveMail = 1;
    } elseif ($moveMail === false) {
        $moveMail = 0;
    }
    if ($moveMail === '0' || $moveMail === '1') {
        $moveMail = (int) $moveMail;
    }
    if ($moveMail !== null && $moveMail !== 0 && $moveMail !== 1) {
        return false;
    }
    if ($moveMail === 0 || $moveMail === 1) {
        $clean['BOOKING_MOVE_MAIL'] = $moveMail;
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }
    $written = @file_put_contents($tmp, $json . "\n", LOCK_EX);
    if ($written === false) {
        @unlink($tmp);

        return false;
    }
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);

        return false;
    }
    @chmod($dest, 0600);

    return is_file($dest) && !is_link($dest);
}

function jeab_flags_audit_append(string $name, int $old, int $new): bool
{
    return jeab_flags_audit_append_value($name, $old, $new);
}

function jeab_flags_audit_append_value(string $name, mixed $old, mixed $new): bool
{
    $dir = jeab_flags_writable_dir();
    if ($dir === null || ($name !== 'BOOKING_MOVE_MAIL_TEST_IDS' && $name !== 'BOOKING_MOVE_MAIL' && !jeab_flag_known($name))) {
        return false;
    }
    $path = $dir . '/flags-audit.log';
    if (!str_starts_with($path, $dir . '/') || is_link($path)) {
        return false;
    }
    $undoOld = is_array($old) ? implode(',', array_map('strval', $old)) : (string) $old;
    $line = json_encode([
        'time' => date('c'),
        'name' => $name,
        'old' => $old,
        'new' => $new,
        'undo' => $name . '=' . $undoOld,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        return false;
    }
    $ok = @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);

    return $ok !== false;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_flags_get_dispatch(array $input): array
{
    unset($input);
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $flags = [];
    foreach (jeab_flag_names() as $name) {
        $flags[$name] = jeab_flag_effective($name);
    }
    $testIds = function_exists('jeab_booking_move_mail_test_ids_from_flags')
        ? jeab_booking_move_mail_test_ids_from_flags()
        : [];

    return [
        'ok' => true,
        'flags' => $flags,
        'BOOKING_MOVE_MAIL_TEST_IDS' => $testIds,
        'BOOKING_MOVE_MAIL' => function_exists('jeab_booking_move_mail_file_value')
            ? jeab_booking_move_mail_file_value()
            : null,
        'status' => 200,
    ];
}

/**
 * @return array{ok:bool, ids:list<int>, rejected:list<int>, error:string}
 */
function jeab_flags_move_mail_test_ids_validate(mixed $raw): array
{
    $fail = static function (string $error, array $rejected = []): array {
        return ['ok' => false, 'ids' => [], 'rejected' => $rejected, 'error' => $error];
    };
    if (is_string($raw) && trim($raw) === '') {
        return ['ok' => true, 'ids' => [], 'rejected' => [], 'error' => ''];
    }
    if (is_array($raw) && $raw === []) {
        return ['ok' => true, 'ids' => [], 'rejected' => [], 'error' => ''];
    }
    $tokens = jeab_flags_move_mail_test_id_tokens($raw);
    if ($tokens === null || $tokens === []) {
        return $fail('value ska vara en lista med boknings-id.');
    }
    $ids = [];
    foreach ($tokens as $id) {
        $ids[$id] = $id;
    }
    $list = array_values($ids);
    sort($list, SORT_NUMERIC);
    if (!function_exists('jeab_store') || !function_exists('jeab_booking_customer_mail_is_test')) {
        return $fail('Testbokningar kan inte läsas.');
    }
    $store = jeab_store();
    $rejected = [];
    foreach ($list as $id) {
        $row = $store->getBooking($id);
        if (!is_array($row) || !jeab_booking_customer_mail_is_test($row)) {
            $rejected[] = $id;
        }
    }
    if ($rejected !== []) {
        return $fail('Bara testbokningar kan listas.', $rejected);
    }

    return ['ok' => true, 'ids' => $list, 'rejected' => [], 'error' => ''];
}

/** @return list<int>|null */
function jeab_flags_move_mail_test_id_tokens(mixed $raw): ?array
{
    if (is_int($raw)) {
        return $raw > 0 ? [$raw] : null;
    }
    if (is_string($raw)) {
        $text = trim($raw);
        if ($text === '') {
            return [];
        }
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $text) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^[1-9]\d*$/', $part) !== 1) {
                return null;
            }
            $ids[] = (int) $part;
        }

        return $ids;
    }
    if (!is_array($raw) || !array_is_list($raw)) {
        return null;
    }
    $ids = [];
    foreach ($raw as $item) {
        $one = jeab_flags_move_mail_test_id_tokens($item);
        if ($one === null) {
            return null;
        }
        foreach ($one as $id) {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * flags.set för BOOKING_MOVE_MAIL_TEST_IDS. Bara testbokningar.
 * Ett id som inte är en testbokning gör att inget skrivs.
 * Tom lista rensar nyckeln. dry saknas eller är 1: ingen fil.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_flags_set_move_mail_test_ids(array $input): array
{
    $dry = jeab_flags_wants_dry($input);
    $name = 'BOOKING_MOVE_MAIL_TEST_IDS';
    if (!array_key_exists('value', $input)) {
        return [
            'ok' => false,
            'error' => 'value ska vara en lista med boknings-id.',
            'status' => 400,
            'written' => false,
            'name' => $name,
            'dry' => $dry,
            'rejected' => [],
        ];
    }
    $checked = jeab_flags_move_mail_test_ids_validate($input['value']);
    if (empty($checked['ok'])) {
        return [
            'ok' => false,
            'error' => (string) $checked['error'],
            'status' => 400,
            'written' => false,
            'name' => $name,
            'dry' => $dry,
            'rejected' => $checked['rejected'],
        ];
    }
    $ids = $checked['ids'];
    $old = function_exists('jeab_booking_move_mail_test_ids_from_flags')
        ? jeab_booking_move_mail_test_ids_from_flags()
        : [];
    if ($dry) {
        return [
            'ok' => true,
            'dry' => true,
            'written' => false,
            'name' => $name,
            'old' => $old,
            'new' => $ids,
            'rejected' => [],
            'status' => 200,
        ];
    }
    $disk = jeab_flags_read_disk();
    $disk[$name] = $ids;
    if (!jeab_flags_write_atomic($disk)) {
        return [
            'ok' => false,
            'error' => 'Flaggan kunde inte sparas.',
            'status' => 400,
            'written' => false,
            'dry' => false,
            'name' => $name,
            'old' => $old,
            'new' => $ids,
            'rejected' => [],
        ];
    }
    if (!jeab_flags_audit_append_value($name, $old, $ids)) {
        return [
            'ok' => false,
            'error' => 'Flaggan sparades men ångerraden kunde inte skrivas.',
            'status' => 400,
            'written' => true,
            'dry' => false,
            'name' => $name,
            'old' => $old,
            'new' => $ids,
            'rejected' => [],
        ];
    }

    return [
        'ok' => true,
        'dry' => false,
        'written' => true,
        'name' => $name,
        'old' => $old,
        'new' => $ids,
        'rejected' => [],
        'status' => 200,
    ];
}

/**
 * flags.set. dry saknas eller är 1: ingen fil. dry=0 skriver privat/flags.json.
 * LEGACY_JSON_WRITES=0 och CONTACTS_STRICT=1 kräver confirm=SAM_YES.
 * BOOKING_MOVE_MAIL_TEST_IDS är en lista av testbokningar, inte 0/1.
 * BOOKING_MOVE_MAIL är 0/1 i samma fil. Env vinner när utskicket läses.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_flags_set_move_mail(array $input): array
{
    $dry = jeab_flags_wants_dry($input);
    $name = 'BOOKING_MOVE_MAIL';
    $value = jeab_flags_set_value($input['value'] ?? null);
    if ($value === null) {
        return [
            'ok' => false,
            'error' => 'value ska vara 0 eller 1.',
            'status' => 400,
            'written' => false,
            'name' => $name,
            'dry' => $dry,
        ];
    }
    $old = function_exists('jeab_booking_move_mail_file_value') ? jeab_booking_move_mail_file_value() : null;
    if ($dry) {
        return [
            'ok' => true,
            'dry' => true,
            'written' => false,
            'name' => $name,
            'old' => $old,
            'new' => $value,
            'status' => 200,
        ];
    }
    $disk = jeab_flags_read_disk();
    $disk[$name] = $value;
    if (!jeab_flags_write_atomic($disk)) {
        return [
            'ok' => false,
            'error' => 'Flaggan kunde inte sparas.',
            'status' => 400,
            'written' => false,
            'dry' => false,
            'name' => $name,
            'old' => $old,
            'new' => $value,
        ];
    }
    if (!jeab_flags_audit_append_value($name, $old, $value)) {
        return [
            'ok' => false,
            'error' => 'Flaggan sparades men ångerraden kunde inte skrivas.',
            'status' => 400,
            'written' => true,
            'dry' => false,
            'name' => $name,
            'old' => $old,
            'new' => $value,
        ];
    }

    return [
        'ok' => true,
        'dry' => false,
        'written' => true,
        'name' => $name,
        'old' => $old,
        'new' => $value,
        'status' => 200,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_flags_set_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'written' => false];
    }
    $name = (string) ($input['name'] ?? '');
    if ($name === 'BOOKING_MOVE_MAIL_TEST_IDS') {
        return jeab_flags_set_move_mail_test_ids($input);
    }
    if ($name === 'BOOKING_MOVE_MAIL') {
        return jeab_flags_set_move_mail($input);
    }
    if (!jeab_flag_known($name)) {
        return [
            'ok' => false,
            'error' => 'Okänd flagga.',
            'status' => 400,
            'written' => false,
            'dry' => jeab_flags_wants_dry($input),
        ];
    }
    $value = jeab_flags_set_value($input['value'] ?? null);
    if ($value === null) {
        return [
            'ok' => false,
            'error' => 'value ska vara 0 eller 1.',
            'status' => 400,
            'written' => false,
            'name' => $name,
            'dry' => jeab_flags_wants_dry($input),
        ];
    }
    $needsConfirm = ($name === 'LEGACY_JSON_WRITES' && $value === 0) || ($name === 'CONTACTS_STRICT' && $value === 1);
    if ($needsConfirm && (string) ($input['confirm'] ?? '') !== 'SAM_YES') {
        return [
            'ok' => false,
            'error' => $name . '=' . $value . ' kräver confirm=SAM_YES.',
            'status' => 400,
            'written' => false,
            'name' => $name,
            'dry' => jeab_flags_wants_dry($input),
        ];
    }
    $dry = jeab_flags_wants_dry($input);
    $before = jeab_flag_effective($name);
    $old = (int) $before['value'];
    if ($dry) {
        return [
            'ok' => true,
            'dry' => true,
            'written' => false,
            'name' => $name,
            'old' => $old,
            'new' => $value,
            'source' => $before['source'],
            'status' => 200,
        ];
    }
    $disk = jeab_flags_read_disk();
    $disk[$name] = $value;
    if (!jeab_flags_write_atomic($disk)) {
        return [
            'ok' => false,
            'error' => 'Flaggan kunde inte sparas.',
            'status' => 400,
            'written' => false,
            'dry' => false,
            'name' => $name,
            'old' => $old,
            'new' => $value,
        ];
    }
    if (!jeab_flags_audit_append($name, $old, $value)) {
        return [
            'ok' => false,
            'error' => 'Flaggan sparades men ångerraden kunde inte skrivas.',
            'status' => 400,
            'written' => true,
            'dry' => false,
            'name' => $name,
            'old' => $old,
            'new' => $value,
        ];
    }
    $after = jeab_flag_effective($name);

    return [
        'ok' => true,
        'dry' => false,
        'written' => true,
        'name' => $name,
        'old' => $old,
        'new' => $value,
        'value' => $after['value'],
        'source' => $after['source'],
        'status' => 200,
    ];
}
