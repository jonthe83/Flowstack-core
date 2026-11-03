<?php

declare(strict_types=1);

/**
 * Vem är inloggad? (för förvalet på Påminnelser och Ordrar)
 *
 * Bara inloggningen. Egen e-post i personregistret, eller en personlig kod vars
 * prefix (saml, samw, eller ett unikt förnamn) sparats mot fingeravtrycket.
 * Den delade kampanjkoden är ingen person: Mina visar Alla, utan fråga.
 * Gamla webbläsarval och who-picks styr inte listan.
 *
 * Diagnos (tillfällig): varje inloggad sidladdning av Påminnelser/Ordrar skrivs till
 * data/.private/who-diag.jsonl med kakformat, fp (inte hemligt), person och vilket
 * personfilter som gällde och varför. Inga koder, inga hashar. Sparas i 3 dygn.
 */

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/** Höj för att glömma alla sparade personval (session) vid nästa sidladdning. 4: Sam L ska inte ärva Sam W. */
const JEAB_WHO_VER = 4;
/** Hur länge ett personval i en adress (who=…) gäller utan att sessionen känner det. */
const JEAB_WHO_URL_TTL = 30 * 60;
const JEAB_WHO_DIAG_KEEP = 3 * 24 * 60 * 60;

/** @return array{ver:string,fp:?string,key:?string} */
function jeab_who_cookie_info(?string $value = null): array
{
    $value = $value ?? (isset($_COOKIE[JEAB_AUTH_COOKIE]) ? (string) $_COOKIE[JEAB_AUTH_COOKIE] : null);
    $out = ['ver' => '', 'fp' => null, 'key' => null];
    if ($value === null || $value === '' || !function_exists('jeab_auth_payload')) {
        return $out;
    }
    $payload = jeab_auth_payload($value);
    if ($payload === null) {
        return $out;
    }
    $parts = explode('.', $payload);
    $ver = (string) ($parts[0] ?? '');
    $out['ver'] = $ver;
    if ($ver === '3') {
        $fp = strtolower((string) ($parts[1] ?? ''));
        if (strlen($fp) === 16 && ctype_xdigit($fp)) {
            $out['fp'] = $fp;
        }
    } elseif ($ver === '2') {
        $entry = jeab_auth_entry_by_email(jeab_auth_decode_email_part((string) ($parts[1] ?? '')));
        $hash = is_array($entry) ? (string) ($entry['hash'] ?? '') : '';
        if ($hash !== '') {
            $out['fp'] = jeab_auth_hash_fingerprint($hash);
        }
    }
    if ($out['fp'] !== null) {
        $out['key'] = 'fp:' . $out['fp'];
    } elseif ($ver === '1') {
        // v1 bär varken kod eller e-post; kakan byts aldrig ut, så den själv är nyckeln.
        $out['key'] = 'c1:' . substr(hash('sha256', $payload), 0, 16);
    }
    return $out;
}

function jeab_who_picks_path(): string
{
    return jeab_data_dir() . '/who-picks.json';
}

/** @return array<string, array{person:string,at:int}> */
function jeab_who_picks_read(): array
{
    $jsonOnly = function_exists('jeab_cutover_forces_json') && jeab_cutover_forces_json('who_picks');
    if (!$jsonOnly && function_exists('jeab_log_who_picks')) {
        $sql = jeab_log_who_picks();
        if (is_array($sql)) {
            return $sql;
        }
    }
    if (!$jsonOnly && function_exists('jeab_cutover_who_picks_map')) {
        $cut = jeab_cutover_who_picks_map();
        if (is_array($cut)) {
            return $cut;
        }
    }
    $path = jeab_who_picks_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function jeab_who_pick_save(string $key, string $personId): bool
{
    if ($key === '' || $personId === '') {
        return false;
    }
    $jsonOnly = function_exists('jeab_cutover_forces_json') && jeab_cutover_forces_json('who_picks');
    if (!$jsonOnly && function_exists('jeab_log_who_pick_put') && jeab_log_who_pick_put($key, $personId)) {
        return true;
    }
    if (!$jsonOnly && function_exists('jeab_cutover_who_pick_put') && jeab_cutover_who_pick_put($key, $personId)) {
        return true;
    }
    $path = jeab_who_picks_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $map = jeab_who_picks_read();
    $map[$key] = ['person' => $personId, 'at' => time()];
    $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return @rename($tmp, $path);
}

function jeab_who_person_by_id(string $id): ?array
{
    foreach (jeab_people() as $row) {
        if (strcasecmp((string) $row['id'], $id) === 0) {
            return $row;
        }
    }
    return null;
}

/** Personen för förvalet i listorna. Bara inloggningen, aldrig ett webbläsarval. */
function jeab_who_me_person(): ?array
{
    $person = function_exists('jeab_auth_user_person') ? jeab_auth_user_person() : null;

    return is_array($person) ? $person : null;
}

function jeab_who_me_source(): string
{
    $src = function_exists('jeab_auth_user_person_source') ? jeab_auth_user_person_source() : '';

    return $src !== '' ? $src : 'none';
}

/** Namnet på den inloggade personen, även när stadsväljaren visar det andra bolaget. */
function jeab_who_me_name(): string
{
    $me = jeab_who_me_person();
    if (!is_array($me)) {
        return '';
    }

    return trim((string) ($me['name'] ?? ''));
}

/**
 * Vems Mina som visas. Namnet följer inloggningen, inte staden man tittar på.
 * Utan känd person: tomt. Ingen fråga.
 */
function jeab_who_scope_note_html(string $company, string $back = ''): string
{
    unset($company, $back);
    $name = jeab_who_me_name();
    if ($name === '') {
        return '';
    }

    return '<p class="who-scope-note">' . e('Mina visar ' . $name . '.') . '</p>';
}

/** Engångsflytt: glöm alla sparade personval från före JEAB_WHO_VER. */
function jeab_who_migrate(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if ((int) ($_SESSION['jeab_who_ver'] ?? 0) === JEAB_WHO_VER) {
        return;
    }
    unset($_SESSION['jeab_reminder_who'], $_SESSION['jeab_ordrar_filter']);
    $_SESSION['jeab_who_ver'] = JEAB_WHO_VER;
    $GLOBALS['_JEAB_WHO_MIGRATED'] = true;
}

/** who=… i adressen är färskt (klickat nyss) om ws= är högst JEAB_WHO_URL_TTL gammalt. */
function jeab_who_url_fresh(): bool
{
    $ws = trim((string) ($_GET['ws'] ?? ''));
    if ($ws === '' || !ctype_digit($ws)) {
        return false;
    }
    $age = time() - (int) $ws;
    return $age >= -120 && $age <= JEAB_WHO_URL_TTL;
}

// ---- Diagnos --------------------------------------------------------------------------------

function jeab_who_diag_path(): string
{
    return jeab_data_dir() . '/who-diag.jsonl';
}

function jeab_who_diag_note(string $page, ?string $who, string $source): void
{
    $GLOBALS['_JEAB_WHO_DIAG'] = ['page' => $page, 'who' => $who ?? 'Alla', 'src' => $source];
}

function jeab_who_ua_family(string $ua): string
{
    $os = preg_match('/iPhone|iPad/i', $ua) ? 'iOS' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/Mac OS X/i', $ua) ? 'Mac' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Linux/i', $ua) ? 'Linux' : '?'))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/CriOS|Chrome\//', $ua) ? 'Chrome' : (preg_match('/FxiOS|Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : '?')));
    if (stripos($ua, 'HeadlessChrome') !== false || stripos($ua, 'jeab-verify') !== false) {
        $br = 'agent';
    }
    return $os . '/' . $br;
}

function jeab_who_diag_record(): void
{
    $note = $GLOBALS['_JEAB_WHO_DIAG'] ?? null;
    if (!is_array($note)) {
        return;
    }
    // Påminnelser sätter noteringen på varje sidvisning. Omskrivningen av
    // diagnosfilen ska inte hålla webbläsaren: svaret är redan byggt.
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    $info = jeab_who_cookie_info();
    $email = function_exists('jeab_auth_user_email') ? jeab_auth_user_email() : null;
    $me = jeab_who_me_person();
    $row = [
        'at' => date('Y-m-d H:i:s'),
        'page' => $note['page'],
        'm' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')),
        'intent' => (string) ($_GET['intent'] ?? ''),
        'co' => jeab_selected_company(),
        'ver' => $info['ver'],
        'fp' => $info['fp'],
        'key' => $info['key'] !== null && str_starts_with($info['key'], 'c1:') ? $info['key'] : null,
        'email' => $email,
        'me' => $me['id'] ?? null,
        'me_src' => jeab_who_me_source(),
        'why' => function_exists('jeab_who_identity_reason') ? jeab_who_identity_reason() : '',
        'who' => $note['who'],
        'who_src' => $note['src'],
        'q_who' => array_key_exists('who', $_GET) ? (string) $_GET['who'] : null,
        'picker' => !empty($GLOBALS['_JEAB_WHO_PICKER_SHOWN']),
        'ws' => array_key_exists('ws', $_GET),
        'migrated' => !empty($GLOBALS['_JEAB_WHO_MIGRATED']),
        'ua' => jeab_who_ua_family((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
    ];
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
        return; // Demo: ingen identitetsdiagnostik sparas.
    }
    $jsonOnly = function_exists('jeab_cutover_forces_json') && jeab_cutover_forces_json('who_diag');
    if (!$jsonOnly && function_exists('jeab_log_who_diag_append') && jeab_log_who_diag_append($row)) {
        return;
    }
    if (!$jsonOnly && function_exists('jeab_cutover_who_diag_sql') && jeab_cutover_who_diag_sql($row)) {
        return;
    }
    $path = jeab_who_diag_path();
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $keep = [];
    if (is_file($path)) {
        $cut = date('Y-m-d H:i:s', time() - JEAB_WHO_DIAG_KEEP);
        foreach (array_slice((array) @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -3000) as $line) {
            $old = json_decode((string) $line, true);
            if (is_array($old) && (string) ($old['at'] ?? '') >= $cut) {
                $keep[] = $line;
            }
        }
    }
    $keep[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($path, implode("\n", $keep) . "\n", LOCK_EX);
}

/**
 * Koderna: fp + etikett (e-post eller andra fält än hash i passwords.json) + vem de är kopplade till.
 * Visar aldrig kod eller hash.
 *
 * @return list<array{fp:string,label:string,person:?string,via:string}>
 */
function jeab_who_codes(): array
{
    $path = function_exists('jeab_auth_path') ? jeab_auth_path() : '';
    $raw = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    $list = is_array($raw) ? (isset($raw['hashes']) && is_array($raw['hashes']) ? $raw['hashes'] : (isset($raw['secret']) ? [] : $raw)) : [];
    $picks = jeab_who_picks_read();
    $config = function_exists('jeab_config') ? (jeab_config()['login_people'] ?? []) : [];
    $out = [];
    foreach ($list as $k => $item) {
        $hash = is_array($item) ? trim((string) ($item['hash'] ?? '')) : trim((string) $item);
        if ($hash === '') {
            continue;
        }
        $fp = jeab_auth_hash_fingerprint($hash);
        $labels = [];
        if (is_string($k) && !is_numeric($k)) {
            $labels[] = $k;
        }
        if (is_array($item)) {
            foreach ($item as $field => $val) {
                if ($field === 'hash' || !is_scalar($val) || str_starts_with((string) $val, '$2')) {
                    continue;
                }
                $labels[] = $field . '=' . substr((string) $val, 0, 60);
            }
        }
        $email = is_array($item) ? (string) ($item['email'] ?? '') : ((is_string($k) && str_contains($k, '@')) ? $k : '');
        $person = null;
        $via = '';
        $head = '';
        if (function_exists('jeab_code_people_read')) {
            $stored = jeab_code_people_read(false)['fp:' . $fp] ?? null;
            if (is_array($stored)) {
                $head = function_exists('jeab_code_head') ? jeab_code_head((string) ($stored['head'] ?? '')) : '';
                if ((string) ($stored['via'] ?? '') === 'manual') {
                    $person = jeab_who_person_by_id((string) ($stored['person'] ?? ''));
                    $via = $person !== null ? 'manuell' : '';
                }
            }
        }
        if ($person === null && function_exists('jeab_certain_identity')) {
            $id = jeab_certain_identity($email !== '' ? $email : null, '', $head);
            if (!empty($id['certain']) && is_array($id['person'] ?? null)) {
                $person = $id['person'];
                $via = (string) ($id['reason'] ?? '') === 'code' ? 'kod' : 'e-post';
            }
        }
        if ($person === null && is_array($config)) {
            foreach ($config as $ck => $cv) {
                if (strcasecmp((string) $ck, 'fp:' . $fp) === 0) {
                    $person = jeab_who_person_by_id((string) $cv);
                    $via = 'config';
                }
            }
        }
        if ($person === null && isset($picks['fp:' . $fp])) {
            $pid = (string) ($picks['fp:' . $fp]['person'] ?? '');
            $person = jeab_who_person_by_id($pid);
            $via = $person !== null ? 'eget val' : ($pid === 'none' ? 'eget val: ingen' : '');
        }
        $out[] = ['fp' => $fp, 'label' => $labels !== [] ? implode(', ', $labels) : '(ingen etikett)', 'person' => $person['name'] ?? null, 'via' => $via];
    }
    return $out;
}

/**
 * En rad per post i lösenordsfilen. Visar högst kodens 9 första tecken.
 * Ingen hash, inget lösenord, inget personnummer.
 *
 * @return list<array{fp:string,head:string,first:string,domain:string,city:string,person:string,flags:string,assign:bool}>
 */
function jeab_login_rows_diag(): array
{
    $path = function_exists('jeab_auth_path') ? jeab_auth_path() : '';
    $raw = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    $list = is_array($raw) ? (isset($raw['hashes']) && is_array($raw['hashes']) ? $raw['hashes'] : (isset($raw['secret']) ? [] : $raw)) : [];
    $storedAll = function_exists('jeab_code_people_read') ? jeab_code_people_read(false) : [];
    if (!is_array($storedAll)) {
        $storedAll = [];
    }
    $out = [];
    foreach ($list as $k => $item) {
        $hash = is_array($item) ? trim((string) ($item['hash'] ?? '')) : trim((string) $item);
        if ($hash === '' || str_starts_with($hash, 'secret')) {
            continue;
        }
        $email = '';
        if (is_array($item)) {
            $email = trim((string) ($item['email'] ?? ''));
        } elseif (is_string($k) && str_contains($k, '@')) {
            $email = trim($k);
        }
        $norm = function_exists('jeab_normalize_person_email') ? jeab_normalize_person_email($email) : null;
        $email = $norm ?? '';
        $local = '';
        $domain = '';
        if ($email !== '' && str_contains($email, '@')) {
            [$local, $domain] = explode('@', $email, 2);
        }
        $flags = [];
        $person = null;
        $city = '';
        $shared = function_exists('jeab_is_shared_login_email') && jeab_is_shared_login_email($email);
        if ($shared) {
            $flags[] = 'delad kod';
        } elseif ($email === '') {
            $flags[] = 'saknar e-post';
        } else {
            $person = function_exists('jeab_person_by_email') ? jeab_person_by_email($email) : null;
            $city = function_exists('jeab_company_from_email') ? (string) (jeab_company_from_email($email) ?? '') : '';
            if ($person === null) {
                $flags[] = 'e-post utan person';
            }
            $same = 0;
            if ($local !== '' && function_exists('jeab_person_name_prefix')) {
                foreach (jeab_people() as $row) {
                    if (is_array($row) && jeab_person_name_prefix($row) === $local) {
                        $same++;
                    }
                }
            }
            if ($same > 1 && ($person === null || $city === '')) {
                $flags[] = 'tvetydigt förnamn';
            }
        }
        $fp = function_exists('jeab_auth_hash_fingerprint') ? jeab_auth_hash_fingerprint($hash) : '';
        $stored = ($fp !== '' && isset($storedAll['fp:' . $fp]) && is_array($storedAll['fp:' . $fp])) ? $storedAll['fp:' . $fp] : [];
        $storedId = trim((string) ($stored['person'] ?? ''));
        $via = (string) ($stored['via'] ?? '');
        $head = function_exists('jeab_code_head') ? jeab_code_head((string) ($stored['head'] ?? '')) : '';
        if ($via === 'manual' && $storedId !== '') {
            $person = null;
            foreach (jeab_people() as $row) {
                if (is_array($row) && strcasecmp((string) ($row['id'] ?? ''), $storedId) === 0) {
                    $person = $row;
                    break;
                }
            }
        } elseif ($via === 'ambiguous' || $via === 'conflict') {
            $person = null;
            $flags[] = $via === 'conflict' ? 'konflikt' : 'tvetydig kod';
        } elseif ($head !== '' && function_exists('jeab_people_by_code_prefix')) {
            $hits = jeab_people_by_code_prefix($head);
            if (count($hits) > 1) {
                $person = null;
                $flags[] = 'tvetydig kod';
            } elseif (count($hits) === 1 && (jeab_person_code_prefix_disambiguated($head, $hits[0]) || !jeab_person_first_name_shared($hits[0]))) {
                $person = $hits[0];
            }
        }
        if ($via !== 'manual' && $head === '' && is_array($person) && function_exists('jeab_person_first_name_shared') && jeab_person_first_name_shared($person)) {
            $person = null;
            $flags[] = 'osäker';
        }
        if ($storedId !== '' && is_array($person) && strcasecmp($storedId, (string) ($person['id'] ?? '')) !== 0 && $via !== 'ambiguous') {
            $flags[] = 'sparad person avviker';
        }
        if ($storedId !== '' && $person === null && function_exists('jeab_person_first_name_shared') && $via !== 'ambiguous' && $via !== 'conflict') {
            foreach (jeab_people() as $row) {
                if (!is_array($row) || strcasecmp((string) ($row['id'] ?? ''), $storedId) !== 0) {
                    continue;
                }
                if (jeab_person_first_name_shared($row)) {
                    $flags[] = 'tvetydig sparad gissning';
                }
                break;
            }
        }
        $first = is_array($person) && function_exists('jeab_person_name_prefix')
            ? jeab_person_name_prefix($person)
            : $local;
        if ($city === '' && is_array($person)) {
            $city = (string) ($person['company'] ?? '');
        }
        $name = is_array($person) ? (string) ($person['name'] ?? '') : '';
        $out[] = [
            'fp' => $fp,
            'head' => $head,
            'first' => $first,
            'domain' => $domain,
            'city' => $city,
            'person' => $name,
            'flags' => implode(', ', $flags),
            // Utan person, delad eller flaggad inloggning kan kopplas (om) manuellt.
            // En manuell koppling pekas inte om härifrån (samma regel som förut).
            'assign' => $fp !== '' && !($via === 'manual' && $storedId !== '') && ($name === '' || $shared || $flags !== []),
        ];
    }

    return $out;
}

function jeab_login_city_label(string $city): string
{
    return match ($city) {
        'jarfalla' => 'Järfälla',
        'malmo' => 'Malmö',
        default => $city,
    };
}

function jeab_login_diag_html(): string
{
    // Förnamnskolumnen visade samma namn som Person. Nu syns namnet en gång.
    $html = '<table id="login-rows"><tr><th>#</th><th>Inloggning (9 tecken)</th><th>E-postdomän</th><th>Ort</th><th>Person</th><th>Flaggor</th></tr>';
    $people = function_exists('jeab_people') ? jeab_people() : [];
    foreach (jeab_login_rows_diag() as $i => $row) {
        $head = function_exists('jeab_code_head') ? jeab_code_head((string) ($row['head'] ?? '')) : '';
        $personCell = e($row['person'] !== '' ? $row['person'] : ($row['first'] !== '' ? $row['first'] . ' (ej kopplad)' : '—'));
        if (!empty($row['assign']) && $people !== []) {
            $personCell = ($row['person'] !== '' ? e($row['person']) . ' ' : '') . '<form method="post" action="' . e(jeab_url('index.php?p=inloggdiag')) . '">'
                . (function_exists('jeab_csrf_field') ? jeab_csrf_field() : '')
                . '<input type="hidden" name="action" value="login.assign">'
                . '<input type="hidden" name="fp" value="' . e((string) $row['fp']) . '">'
                . '<select name="person" aria-label="Peka inloggning">'
                . '<option value="">Välj person</option>';
            foreach ($people as $person) {
                if (!is_array($person)) {
                    continue;
                }
                $sel = strcasecmp((string) ($person['name'] ?? ''), (string) $row['person']) === 0 ? ' selected' : '';
                $personCell .= '<option value="' . e((string) ($person['id'] ?? '')) . '"' . $sel . '>' . e((string) ($person['name'] ?? '')) . '</option>';
            }
            $personCell .= '</select> <button type="submit">Peka</button></form>';
        }
        $html .= '<tr' . ($row['flags'] !== '' ? ' class="is-flag"' : '') . '><td>' . ($i + 1) . '</td><td>'
            . e($head !== '' ? $head : '—') . '</td><td>' . e($row['domain']) . '</td><td>' . e(jeab_login_city_label($row['city'])) . '</td><td>'
            . $personCell . '</td><td>' . e($row['flags']) . '</td></tr>';
    }

    return $html . '</table>';
}

function jeab_login_diag_allowed(): bool
{
    if (function_exists('jeab_is_admin') && jeab_is_admin()) {
        return true;
    }
    // Bara en säker personlig inloggning. login_people «fp:» och who_me räcker inte.
    $person = function_exists('jeab_personal_login_person') ? jeab_personal_login_person() : null;

    return is_array($person) && strcasecmp((string) ($person['id'] ?? ''), 'SamL') === 0;
}

/** POST p=inloggdiag. GET ritas av index.php inuti Övrigt. Ingen DDL här. */
function jeab_login_diag_page(): void
{
    if (strtolower((string) ($_GET['p'] ?? '')) !== 'inloggdiag') {
        return;
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    if (!jeab_login_diag_allowed()) {
        http_response_code(403);
        echo 'Bara admin.';
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'user.responsibility') {
        if (!function_exists('jeab_csrf_ok') || !jeab_csrf_ok()) {
            http_response_code(419);
            echo 'Ogiltig session. Ladda om sidan.';
            exit;
        }
        $pid = trim((string) ($_POST['person'] ?? ''));
        $known = false;
        foreach (jeab_people() as $p) {
            if (is_array($p) && (string) ($p['id'] ?? '') === $pid) {
                $known = true;
                break;
            }
        }
        if (!$known || !function_exists('jeab_user_responsibility_save')) {
            http_response_code(400);
            echo 'Okänd person.';
            exit;
        }
        $me = function_exists('jeab_personal_login_person') ? jeab_personal_login_person() : null;
        $rules = jeab_user_responsibility_rules_from_post(is_array($_POST['resp'] ?? null) ? $_POST['resp'] : []);
        if (!jeab_user_responsibility_save($pid, (string) ($_POST['note'] ?? ''), $rules, is_array($me) ? (string) ($me['id'] ?? '') : 'admin')) {
            http_response_code(503);
            echo 'Kunde inte spara (ingen databas).';
            exit;
        }
        $back = jeab_url('index.php?p=inloggdiag') . '#u-' . rawurlencode($pid);
        header('Location: ' . $back, true, 303);
        exit;
    }
    if ($action !== 'login.assign') {
        http_response_code(400);
        echo 'Okänd åtgärd.';
        exit;
    }
    if (!function_exists('jeab_csrf_ok') || !jeab_csrf_ok()) {
        http_response_code(419);
        echo 'Ogiltig session. Ladda om sidan.';
        exit;
    }
    $fp = strtolower(trim((string) ($_POST['fp'] ?? '')));
    $personId = trim((string) ($_POST['person'] ?? ''));
    if ($fp === '' || !ctype_xdigit($fp) || strlen($fp) !== 16 || $personId === '') {
        http_response_code(400);
        echo 'Ogiltig inloggning.';
        exit;
    }
    $row = null;
    foreach (jeab_login_rows_diag() as $one) {
        if (($one['fp'] ?? '') === $fp) {
            $row = $one;
            break;
        }
    }
    if ($row === null) {
        http_response_code(404);
        echo 'Inloggningen finns inte.';
        exit;
    }
    if (empty($row['assign'])) {
        http_response_code(409);
        echo 'Inloggningen pekar redan säkert på en person.';
        exit;
    }
    $target = null;
    foreach (jeab_people() as $person) {
        if (is_array($person) && strcasecmp((string) ($person['id'] ?? ''), $personId) === 0) {
            $target = $person;
            break;
        }
    }
    if ($target === null) {
        http_response_code(400);
        echo 'Okänd person.';
        exit;
    }
    jeab_code_people_save($fp, (string) $target['id'], 'manual', null);
    $back = jeab_url('index.php?p=inloggdiag');
    if (function_exists('jeab_redirect')) {
        jeab_redirect($back, 303);
    }
    header('Location: ' . $back, true, 303);
    exit;
}

/** GET p=whodiag (bara inloggad): kodlista + senaste diagnosraderna. */
function jeab_who_diag_page(): void
{
    if (strtolower((string) ($_GET['p'] ?? '')) !== 'whodiag' || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $rows = [];
    $sqlRows = function_exists('jeab_log_who_diag_tail') ? jeab_log_who_diag_tail(300) : null;
    if (is_array($sqlRows)) {
        $rows = $sqlRows;
    }
    $path = jeab_who_diag_path();
    if ($rows === [] && !is_array($sqlRows) && is_file($path)) {
        foreach (array_slice((array) @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -300) as $line) {
            $r = json_decode((string) $line, true);
            if (is_array($r)) {
                $rows[] = $r;
            }
        }
    }
    $rows = array_reverse($rows);
    echo '<!doctype html><meta charset="utf-8"><title>whodiag</title><style>body{font:12px system-ui;margin:12px}td,th{border:1px solid #ccc;padding:2px 4px;text-align:left}</style>';
    $mine = jeab_who_cookie_info();
    echo '<p>Din kaka: ver=' . e($mine['ver']) . ' fp=' . e((string) $mine['fp']) . ' me=' . e((string) (jeab_who_me_person()['id'] ?? '')) . ' (' . e(jeab_who_me_source()) . ')</p>';
    echo '<h2>Koder</h2><table id="codes"><tr><th>#</th><th>fp</th><th>etikett</th><th>person</th><th>via</th></tr>';
    foreach (jeab_who_codes() as $i => $c) {
        echo '<tr><td>' . ($i + 1) . '</td><td>' . e($c['fp']) . '</td><td>' . e($c['label']) . '</td><td>' . e((string) $c['person']) . '</td><td>' . e($c['via']) . '</td></tr>';
    }
    echo '</table><h2>Senaste sidladdningar</h2><table id="diag"><tr>';
    $cols = ['at', 'page', 'm', 'intent', 'co', 'ver', 'fp', 'key', 'email', 'me', 'me_src', 'who', 'who_src', 'q_who', 'picker', 'ws', 'migrated', 'ua'];
    foreach ($cols as $c) {
        echo '<th>' . e($c) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($cols as $c) {
            $v = $r[$c] ?? '';
            echo '<td>' . e(is_bool($v) ? ($v ? '1' : '') : (string) $v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

/** Användare: en rad per person (namnet en gång), inloggningar som 9-teckenshuvud, ansvarsområde redigerbart. */
function jeab_users_responsibility_html(): string
{
    if (!function_exists('jeab_user_responsibility_all')) {
        return '';
    }
    $logins = [];
    foreach (jeab_login_rows_diag() as $row) {
        if ($row['person'] !== '') {
            $head = function_exists('jeab_code_head') ? jeab_code_head((string) $row['head']) : '';
            if ($head !== '') {
                $logins[$row['person']][$head] = true;
            }
        }
    }
    jeab_user_responsibility_reset_cache();
    $all = jeab_user_responsibility_all(true);
    $html = '<div class="users-list">';
    foreach (jeab_people() as $person) {
        if (!is_array($person)) {
            continue;
        }
        $pid = (string) ($person['id'] ?? '');
        $name = (string) ($person['name'] ?? $pid);
        $cur = $all[$pid] ?? ['note' => '', 'rules' => []];
        $has = [];
        foreach ($cur['rules'] as $rule) {
            foreach ($rule['roles'] as $role) {
                $has[$rule['product'] . '|' . $rule['region'] . '|' . $role] = true;
            }
        }
        $heads = array_keys($logins[$name] ?? []);
        $html .= '<section class="user-card" id="u-' . e($pid) . '"><h2>' . e($name) . '</h2>'
            . '<p class="muted">' . e(jeab_login_city_label((string) ($person['company'] ?? '')))
            . ($heads !== [] ? ' · inloggning ' . e(implode(', ', $heads)) : '') . '</p>'
            . '<form method="post" action="' . e(jeab_url('index.php?p=inloggdiag')) . '">'
            . (function_exists('jeab_csrf_field') ? jeab_csrf_field() : '')
            . '<input type="hidden" name="action" value="user.responsibility">'
            . '<input type="hidden" name="person" value="' . e($pid) . '">'
            . '<label>Ansvarsområde<br><textarea name="note" rows="2" cols="60">' . e($cur['note']) . '</textarea></label>'
            . '<table class="resp-grid"><tr><th>Produkt</th><th>Region</th>';
        foreach (JEAB_RESP_ROLES as $label) {
            $html .= '<th>' . e($label) . '</th>';
        }
        $html .= '</tr>';
        foreach (JEAB_RESP_PRODUCTS as $p => $plabel) {
            foreach (JEAB_RESP_REGIONS as $reg => $rlabel) {
                $html .= '<tr><td>' . e($plabel) . '</td><td>' . e($rlabel) . '</td>';
                foreach (JEAB_RESP_ROLES as $role => $label) {
                    $html .= '<td><input type="checkbox" aria-label="' . e($plabel . ' ' . $rlabel . ' ' . $label) . '" name="resp[' . e($p . '|' . $reg) . '][]" value="' . e($role) . '"'
                        . (isset($has[$p . '|' . $reg . '|' . $role]) ? ' checked' : '') . '></td>';
                }
                $html .= '</tr>';
            }
        }
        $html .= '</table><button type="submit">Spara</button></form></section>';
    }
    return $html . '</div>';
}
