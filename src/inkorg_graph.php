<?php

declare(strict_types=1);

/**
 * Inkorg via Microsoft Graph (ersätter Help Scout som källa).
 * Läser kontakt@jarfalla.example.test och kontakt@malmo.example.test. Skickar via sendMail
 * bara när en användare trycker Skicka (inställning send_enabled, standard på).
 *
 * Hemlighet (aldrig git/loggar/backup): data/.private/secrets/m365.json
 *   {"tenant_id","client_id","client_secret","client_state"}
 * Tillstånd: data/.private/secrets/m365-token.json (token-cache), inkorg/graph-state.json
 * (prenumerationer, deltaLinks, conversationId → ärende).
 */

const JEAB_GRAPH_HOOK_URL = 'https://flowstack.example.test/index.php?graph_hook=1';
const JEAB_GRAPH_DELTA_RECOVERY_PAGES = 20;
const JEAB_GRAPH_DELTA_RECOVERY_SECONDS = 25;
const JEAB_GRAPH_BACKFILL_FRESH_SEC = 1800;

/** Webhook-kön slutar försöka efter så här många misslyckade intag. Raden ligger kvar. */
const JEAB_GRAPH_HOOK_ATTEMPTS = 8;

/** Första gången ett mejl ses inom två dygn ska ett lead fortfarande få offert och påminnelse. Äldre historik förblir tyst. */
const JEAB_GRAPH_LEAD_CATCHUP_SEC = 172800;

function jeab_graph_mailboxes(): array
{
    return ['jarfalla' => 'kontakt@jarfalla.example.test', 'malmo' => 'kontakt@malmo.example.test'];
}

/** Mejlboxar som läses in via Graph: bolag som ligger i Help Scout hoppas över. */
function jeab_graph_ingest_mailboxes(): array
{
    return array_filter(jeab_graph_mailboxes(), fn($co) => !function_exists('jeab_inkorg_company_live') || jeab_inkorg_company_live((string) $co), ARRAY_FILTER_USE_KEY);
}

/** Inkorgen pollar aldrig hela Malmös kontakt@. Bara Help Scout-ärenden till Kim går in i flödet. */
function jeab_graph_poll_mailboxes(): array
{
    $boxes = jeab_graph_ingest_mailboxes();
    unset($boxes['malmo']);
    return $boxes;
}

function jeab_graph_secret_path(string $name): string
{
    return jeab_data_dir() . '/secrets/' . $name;
}

function jeab_graph_json_read(string $p): array
{
    $graphId = function_exists('jeab_cutover_graph_id') ? jeab_cutover_graph_id($p) : '';
    if ($graphId === 'graph_state' && function_exists('jeab_remain_sql_mode') && jeab_remain_sql_mode('graph_state') === 'sql' && function_exists('jeab_remain_doc_get')) {
        $doc = jeab_remain_doc_get('graph_state');
        if (is_array($doc)) {
            return $doc;
        }
    }
    if (function_exists('jeab_cutover_graph_read')) {
        $sql = jeab_cutover_graph_read($p);
        if (is_array($sql)) {
            return $sql;
        }
    }
    $d = is_file($p) ? json_decode((string) file_get_contents($p), true) : null;
    return is_array($d) ? $d : [];
}

function jeab_graph_json_write(string $p, array $d): void
{
    $graphId = function_exists('jeab_cutover_graph_id') ? jeab_cutover_graph_id($p) : '';
    if ($graphId === 'graph_state' && function_exists('jeab_remain_sql_mode')) {
        if (jeab_remain_sql_mode('graph_state') === 'json') {
            jeab_graph_json_write_file($p, $d);

            return;
        }
        if (function_exists('jeab_remain_doc_put') && jeab_remain_doc_put('graph_state', $d)) {
            return;
        }
    }
    // graph_status läser SQL först. En lyckad SQL-skrivning lämnar filen orörd.
    if (function_exists('jeab_cutover_graph_write') && jeab_cutover_graph_write($p, $d)) {
        return;
    }
    $graphId = function_exists('jeab_cutover_graph_id') ? jeab_cutover_graph_id($p) : '';
    if ($graphId !== '' && function_exists('jeab_data_type_forced') && jeab_data_type_forced('sql', $graphId)) {
        return;
    }
    jeab_graph_json_write_file($p, $d);
}

function jeab_graph_json_write_file(string $p, array $d): void
{
    if (!is_dir(dirname($p))) {
        @mkdir(dirname($p), 0700, true);
    }
    $tmp = $p . '.tmp';
    file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($tmp, 0600);
    @rename($tmp, $p);
}

function jeab_graph_config(): array
{
    $c = jeab_graph_json_read(jeab_graph_secret_path('m365.json'));
    foreach (['tenant_id', 'client_id', 'client_secret'] as $k) {
        if (trim((string) ($c[$k] ?? '')) === '') {
            return [];
        }
    }
    return $c;
}

function jeab_graph_configured(): bool
{
    return jeab_graph_config() !== [];
}

/** Sparar nycklarna. client_state skapas om den saknas. Inget ekas tillbaka. */
function jeab_graph_secret_save(array $in): array
{
    $old = jeab_graph_json_read(jeab_graph_secret_path('m365.json'));
    $new = [];
    foreach (['tenant_id', 'client_id', 'client_secret'] as $k) {
        $new[$k] = trim((string) ($in[$k] ?? ($old[$k] ?? '')));
        if ($new[$k] === '') {
            return ['ok' => false, 'error' => $k . ' saknas.'];
        }
    }
    $new['client_state'] = (string) ($old['client_state'] ?? '') !== '' ? (string) $old['client_state'] : bin2hex(random_bytes(24));
    $new['saved_at'] = date('c');
    jeab_graph_json_write(jeab_graph_secret_path('m365.json'), $new);
    @unlink(jeab_graph_secret_path('m365-token.json'));
    return ['ok' => true, 'saved' => true];
}

/** Feltext utan hemligheter. curl_error innehåller inte Authorization. */
function jeab_graph_http_note(array $r): string
{
    if ((int) ($r['code'] ?? 0) !== 0 || empty($r['transport'])) {
        return '';
    }
    $errno = (int) ($r['curl_errno'] ?? 0);
    $ms = (int) ($r['ms'] ?? 0);
    $err = trim(str_replace(["\r", "\n"], ' ', (string) ($r['curl_error'] ?? '')));
    if (strlen($err) > 160) {
        $err = substr($err, 0, 160);
    }
    $note = ' curl_errno=' . $errno . ' ms=' . $ms;
    if ($err !== '') {
        $note .= ' ' . $err;
    }

    return $note;
}

/**
 * @param array<string, mixed> $r
 * @return array{ok: false, error: string, transport?: true, blocked?: true}
 */
function jeab_graph_fail(string $prefix, array $r): array
{
    $out = ['ok' => false, 'error' => $prefix . (int) ($r['code'] ?? 0) . jeab_graph_http_note($r)];
    if (!empty($r['transport'])) {
        $out['transport'] = true;
    }
    if (!empty($r['blocked'])) {
        $out['blocked'] = true;
    }

    return $out;
}

/**
 * Reply som inte är HTTP 202 och inte ett tydligt 4xx/5xx.
 * Timeout (kod 0) kan redan vara mottagen. Då får ingen andra kopia gå via sendMail.
 *
 * @param array<string, mixed> $r
 */
function jeab_graph_reply_uncertain(array $r): bool
{
    $code = (int) ($r['code'] ?? 0);
    if ($code === 202) {
        return false;
    }

    return !empty($r['transport']) || $code < 400;
}

/**
 * @param array<string, mixed> $r
 * @return array{ok: false, error: string, transport?: true, blocked?: true}
 */
function jeab_graph_reply_uncertain_fail(array $r): array
{
    $code = (int) ($r['code'] ?? 0);
    $fail = ['ok' => false, 'error' => 'graph ' . $code];
    if (!empty($r['transport']) || $code === 0) {
        $fail['transport'] = true;
    }
    if (!empty($r['blocked'])) {
        $fail['blocked'] = true;
    }

    return $fail;
}

function jeab_graph_http_log_zero(string $method, string $url, int $errno, string $error, int $ms, bool $blocked): void
{
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    if ($blocked && strtoupper($method) !== 'POST') {
        return;
    }
    $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
    $err = trim(str_replace(["\r", "\n"], ' ', $error));
    if (strlen($err) > 180) {
        $err = substr($err, 0, 180);
    }
    $line = 'graph http status 0'
        . ($blocked ? ' blocked=1' : '')
        . ' curl_errno=' . $errno
        . ' curl_error=' . $err
        . ' ms=' . $ms
        . ' method=' . strtoupper($method)
        . ($host !== '' ? ' host=' . $host : '');
    jeab_log_app_error('inkorg', $line);
}

/** Bara Graph-läsning, och bara när anroparen inte ska skriva i databasen efteråt. */
function jeab_graph_read_may_release(string $method): bool
{
    if (!empty($GLOBALS['_JEAB_PDO_HOLD'])) {
        return false;
    }

    return in_array(strtoupper($method), ['GET', 'HEAD'], true);
}

function jeab_graph_http(string $method, string $url, ?array $body = null, array $headers = [], ?string $form = null): array
{
    if (function_exists('jeab_page_blocks_external') && jeab_page_blocks_external()) {
        jeab_graph_http_log_zero($method, $url, 0, '', 0, true);

        return ['code' => 0, 'body' => [], 'blocked' => true];
    }
    if (function_exists('jeab_auto_quote_web_budget_active') && jeab_auto_quote_web_budget_active()) {
        $left = jeab_auto_quote_web_remaining_seconds();
        if ($left < 1) {
            jeab_graph_http_log_zero($method, $url, 0, 'quote budget', 0, false);

            return ['code' => 0, 'body' => [], 'transport' => true, 'curl_errno' => 28, 'curl_error' => 'quote budget', 'ms' => 0];
        }
        $GLOBALS['_JEAB_GRAPH_HTTP_TIMEOUT'] = min(
            (int) ($GLOBALS['_JEAB_GRAPH_HTTP_TIMEOUT'] ?? 25) ?: 25,
            $left
        );
    }
    if (jeab_graph_read_may_release($method) && function_exists('jeab_pdo_release_if_idle')) {
        jeab_pdo_release_if_idle();
    }
    if (isset($GLOBALS['_JEAB_GRAPH_HTTP']) && is_callable($GLOBALS['_JEAB_GRAPH_HTTP'])) {
        return ($GLOBALS['_JEAB_GRAPH_HTTP'])($method, $url, $body, $headers);
    }
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('graph', $method . ' ' . $url)) {
        return ['code' => 200, 'body' => ['value' => [], 'demo' => true], 'demo' => true];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        jeab_graph_http_log_zero($method, $url, 0, 'curl_init misslyckades', 0, false);

        return ['code' => 0, 'body' => [], 'transport' => true, 'curl_errno' => 0, 'curl_error' => 'curl_init misslyckades', 'ms' => 0];
    }
    $h = $headers;
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
    }
    $graphTimeout = function_exists('jeab_inkorg_external_timeout') ? jeab_inkorg_external_timeout(25) : 25;
    $cap = (int) ($GLOBALS['_JEAB_GRAPH_HTTP_TIMEOUT'] ?? 0);
    if ($cap > 0) {
        $graphTimeout = min($graphTimeout, $cap);
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $graphTimeout,
        CURLOPT_CONNECTTIMEOUT => $graphTimeout < 25 ? min(4, $graphTimeout) : 10,
        CURLOPT_HTTPHEADER => $h,
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $form);
    } elseif ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    if (jeab_graph_read_may_release($method) && function_exists('jeab_pdo_release_if_idle')) {
        jeab_pdo_release_if_idle();
    }
    $started = hrtime(true);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = (int) curl_errno($ch);
    $error = (string) curl_error($ch);
    $ms = (int) round((hrtime(true) - $started) / 1e6);
    curl_close($ch);
    if (function_exists('jeab_resend_profile_add')) {
        jeab_resend_profile_add('graph', $ms);
    }
    $d = null;
    $jsonError = '';
    if (is_string($raw) && $raw !== '') {
        $d = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $jsonError = json_last_error_msg();
            $d = null;
        }
    }
    $out = ['code' => $code, 'body' => is_array($d) ? $d : []];
    if ($jsonError !== '') {
        $out['json_error'] = $jsonError;
    }
    if ($code === 0) {
        $safe = trim(str_replace(["\r", "\n"], ' ', $error));
        if (strlen($safe) > 180) {
            $safe = substr($safe, 0, 180);
        }
        $out['transport'] = true;
        $out['curl_errno'] = $errno;
        $out['curl_error'] = $safe;
        $out['ms'] = $ms;
        jeab_graph_http_log_zero($method, $url, $errno, $safe, $ms, false);
    }

    return $out;
}

function jeab_graph_token(): ?string
{
    if (isset($GLOBALS['_JEAB_GRAPH_HTTP']) && empty($GLOBALS['_JEAB_GRAPH_TOKEN_VIA_HTTP'])) {
        return 'test';
    }
    $c = jeab_graph_config();
    if (!$c) {
        return null;
    }
    $cp = jeab_graph_secret_path('m365-token.json');
    $cache = jeab_graph_json_read($cp);
    if ((string) ($cache['access_token'] ?? '') !== '' && (int) ($cache['expires_at'] ?? 0) > time() + 120) {
        return (string) $cache['access_token'];
    }
    $r = jeab_graph_http('POST', 'https://login.microsoftonline.com/' . rawurlencode((string) $c['tenant_id']) . '/oauth2/v2.0/token', null,
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials']));
    $t = (string) ($r['body']['access_token'] ?? '');
    if ($t === '') {
        return null;
    }
    jeab_graph_json_write($cp, ['access_token' => $t, 'expires_at' => time() + (int) ($r['body']['expires_in'] ?? 3000)]);
    return $t;
}

function jeab_graph_api(string $method, string $path, ?array $body = null, array $extra = []): array
{
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('graph', $method . ' ' . $path)) {
        return ['code' => 200, 'body' => ['value' => [], 'demo' => true], 'demo' => true];
    }
    $t = jeab_graph_token();
    if ($t === null) {
        $out = ['code' => 0, 'body' => ['error' => ['message' => 'Graph ej konfigurerat.']]];
        if (function_exists('jeab_page_blocks_external') && jeab_page_blocks_external()) {
            $out['body'] = [];
            $out['blocked'] = true;
        }

        return $out;
    }
    $url = str_starts_with($path, 'https://') ? $path : 'https://graph.microsoft.com/v1.0/' . ltrim($path, '/');
    return jeab_graph_http($method, $url, $body, array_merge(['Authorization: Bearer ' . $t], $extra));
}

/* ---------- Tillstånd ---------- */

function jeab_graph_state(): array
{
    return jeab_graph_json_read(jeab_inkorg_dir() . '/graph-state.json');
}

function jeab_graph_state_update(callable $fn): mixed
{
    if (function_exists('jeab_remain_sql_mode') && jeab_remain_sql_mode('graph_state') === 'sql' && function_exists('jeab_remain_pdo') && jeab_remain_pdo() instanceof PDO && function_exists('jeab_remain_doc_put')) {
        $lockPath = jeab_inkorg_dir() . '/graph-state.json.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock && flock($lock, LOCK_EX)) {
            try {
                $d = jeab_graph_state();
                $r = $fn($d);
                if (jeab_remain_doc_put('graph_state', $d)) {
                    return $r;
                }
                jeab_graph_json_write_file(jeab_inkorg_dir() . '/graph-state.json', $d);

                return $r;
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('graph_state') && function_exists('jeab_cutover_doc_put')) {
        $d = jeab_graph_state();
        $r = $fn($d);
        jeab_cutover_doc_put('graph_state', $d);
        return $r;
    }
    return jeab_inkorg_locked('graph-state.json', function (?array $d) use ($fn) {
        $d = $d ?? [];
        $r = $fn($d);
        return [$r, $d];
    });
}

/* ---------- Intag ---------- */

/**
 * Dubblett mot Help Scout-import: samma avsändare (From eller Reply-To, t.ex. kontaktformulär)
 * + rubrik i ett helpscout-ärende. Returnerar [thread_id, exakt dubblett (inom 20 min)].
 */
function jeab_graph_hs_match(string $from, string $subject, string $received, array $alt = []): array
{
    $hit = jeab_inkorg_twin_find(array_merge([$from], $alt), $subject, $received, 'helpscout', 60 * 86400);
    return [$hit['thread_id'], $hit['exact']];
}

/**
 * Ett mejl från tick. Ny anslutning om den legat stilla.
 * 2006/2013: samma Graph-id en gång till. message id (sha256) gör omförsöket till dubblett om raden redan hann sparas.
 */
function jeab_graph_mail_is_fresh(string $received): bool
{
    $ts = strtotime($received);
    if ($ts === false || $ts <= 0) {
        return false;
    }
    $window = (int) ($GLOBALS['_JEAB_GRAPH_BACKFILL_FRESH_SEC'] ?? JEAB_GRAPH_BACKFILL_FRESH_SEC);

    return $ts >= time() - max(0, $window);
}

function jeab_graph_mail_within(string $received, int $window): bool
{
    $ts = strtotime($received);
    if ($ts === false || $ts <= 0) {
        return false;
    }

    return $ts >= time() - max(0, $window);
}

/** Äldre än 48 timmar är historik och får inte auto. Inom 48 timmar är mejlet fortfarande ett lead, även om tick kom efter 30 minuter. */
function jeab_graph_received_forces_backfill(string $received): bool
{
    $window = (int) ($GLOBALS['_JEAB_GRAPH_LEAD_CATCHUP_SEC'] ?? JEAB_GRAPH_LEAD_CATCHUP_SEC);

    return !jeab_graph_mail_within($received, $window);
}

/** Lucka och catch-up: äldre än 48 timmar utan auto. Senaste två dygnen följer vanliga regler. */
function jeab_graph_ingest_backfill(string $company, string $graphId, string $received): array
{
    $backfilled = jeab_graph_received_forces_backfill($received);

    return jeab_graph_ingest_once($company, $graphId, !$backfilled, $backfilled);
}

function jeab_graph_ingest_once(string $company, string $graphId, bool $auto = true, bool $backfilled = false): array
{
    if ($backfilled) {
        $auto = false;
    }
    if (function_exists('jeab_pdo_retry_mail')) {
        $out = jeab_pdo_retry_mail(static function () use ($company, $graphId, $auto, $backfilled): array {
            return jeab_graph_ingest_id($company, $graphId, $auto, $backfilled);
        });

        return is_array($out) ? $out : ['ok' => false, 'error' => 'okänt fel'];
    }

    return jeab_graph_ingest_id($company, $graphId, $auto, $backfilled);
}

/** Hämtar ett meddelande från Graph och lägger det i Inkorgen. Idempotent. */
function jeab_graph_ingest_id(string $company, string $graphId, bool $auto = true, bool $backfilled = false): array
{
    if ($company === 'malmo' && !isset(jeab_graph_ingest_mailboxes()[$company])) {
        return ['ok' => true, 'skipped' => 'helpscout'];
    }
    $box = jeab_graph_mailboxes()[$company] ?? '';
    if ($box === '' || $graphId === '') {
        return ['ok' => false, 'error' => 'okänd mejlbox'];
    }
    $sel = 'id,internetMessageId,conversationId,from,toRecipients,ccRecipients,subject,receivedDateTime,body,isDraft,internetMessageHeaders';
    if (function_exists('jeab_pdo_hold_push')) {
        jeab_pdo_hold_push();
    }
    try {
        $r = jeab_graph_api('GET', 'users/' . rawurlencode($box) . '/messages/' . rawurlencode($graphId) . '?$select=' . $sel, null, ['Prefer: outlook.body-content-type="text"']);
        if ((int) $r['code'] !== 200) {
            if ((int) $r['code'] === 404) {
                return ['ok' => true, 'skipped' => 'gone'];
            }

            return ['ok' => false, 'error' => jeab_graph_public_error($r)];
        }

        return jeab_graph_ingest_message($company, $r['body'], $auto, $backfilled);
    } finally {
        if (function_exists('jeab_pdo_hold_pop')) {
            jeab_pdo_hold_pop();
        }
    }
}

/** Feltext till status och logg. Ingen clientState, ingen mejltext. */
function jeab_graph_public_error(array $r): string
{
    $msg = trim(str_replace(["\r", "\n"], ' ', (string) ($r['body']['error']['message'] ?? '')));
    if (function_exists('jeab_log_mask_text')) {
        $msg = trim(jeab_log_mask_text($msg));
    }
    if (function_exists('mb_substr')) {
        $msg = mb_substr($msg, 0, 180, 'UTF-8');
    } else {
        $msg = substr($msg, 0, 180);
    }
    $line = 'graph ' . (int) ($r['code'] ?? 0);
    if ($msg !== '') {
        $line .= ': ' . $msg;
    }
    $line .= jeab_graph_http_note($r);
    if (strlen($line) > 240) {
        $line = substr($line, 0, 240);
    }
    return $line;
}

function jeab_graph_ingest_message(string $company, array $g, bool $auto = true, bool $backfilled = false): array
{
    // Spärr för alla vägar (delta, lista, hook-kö, catch-up): mejl äldre än 48 timmar får aldrig auto-offert.
    // Ett lead som tick hann med inom två dygn ska fortfarande få offert och påminnelse.
    if (!$backfilled && jeab_graph_received_forces_backfill((string) ($g['receivedDateTime'] ?? ''))) {
        $backfilled = true;
    }
    if ($backfilled) {
        $auto = false;
    }
    $box = jeab_graph_mailboxes()[$company] ?? '';
    if (!isset(jeab_graph_ingest_mailboxes()[$company])) {
        return ['ok' => true, 'skipped' => 'helpscout'];
    }
    if (!empty($g['isDraft'])) {
        return ['ok' => true, 'skipped' => 'draft'];
    }
    $from = (string) ($g['from']['emailAddress']['address'] ?? '');
    $conv = (string) ($g['conversationId'] ?? '');
    $mid = trim((string) ($g['internetMessageId'] ?? ''), '<> ');
    if ($mid === '') {
        $mid = 'graph-' . (string) ($g['id'] ?? '') . '@graph.jeab';
    }
    $received = (string) ($g['receivedDateTime'] ?? date('c'));
    $subject = (string) ($g['subject'] ?? '');
    $headers = [];
    foreach ((array) ($g['internetMessageHeaders'] ?? []) as $h) {
        $n = (string) ($h['name'] ?? '');
        $key = strtolower($n);
        if (!in_array($key, ['in-reply-to', 'references', 'list-unsubscribe', 'precedence', 'auto-submitted', 'reply-to', 'delivered-to', 'authentication-results', 'x-jeab-test'], true)) {
            continue;
        }
        $value = (string) ($h['value'] ?? '');
        if ($key === 'authentication-results' && isset($headers[$n]) && (string) $headers[$n] !== '') {
            // Flera rader: ett förfalskat pass får inte skriva över mottagarens resultat.
            $headers[$n] .= "\n" . $value;
        } else {
            $headers[$n] = $value;
        }
    }
    $existingId = substr(hash('sha256', $mid), 0, 16);
    $storedEarly = jeab_inkorg_get($existingId);
    $graphBodyEarly = (string) ($g['body']['content'] ?? '');
    if (is_array($storedEarly) && jeab_inkorg_body_same_mail((string) ($storedEarly['body'] ?? ''), $graphBodyEarly)) {
        $storedTid = trim((string) ($storedEarly['thread_id'] ?? ''));
        if ($storedTid === '') {
            $storedTid = trim((string) ($storedEarly['id'] ?? $existingId));
        }
        // Tom karta ger nästa mejl i konversationen ett nytt g-ärende. En redan satt
        // koppling (Help Scout-absorb) skrivs inte över av samma mejl igen.
        if ($conv !== '' && $storedTid !== '') {
            jeab_graph_state_update(static function (array &$d) use ($conv, $storedTid): bool {
                if (!isset($d['conv']) || !is_array($d['conv'])) {
                    $d['conv'] = [];
                }
                if (trim((string) ($d['conv'][$conv] ?? '')) === '') {
                    $d['conv'][$conv] = $storedTid;
                }

                return true;
            });
        }

        return ['ok' => true, 'duplicate' => true, 'id' => $existingId, 'thread_id' => $storedTid];
    }
    $state = jeab_graph_state();
    $tid = $conv !== '' ? (string) ($state['conv'][$conv] ?? '') : '';
    if ($tid === '') {
        $replyTo = (string) (jeab_inkorg_addr((string) (jeab_inkorg_header($headers, 'Reply-To') ?? ''))[0] ?? '');
        [$hsTid, $exact] = jeab_graph_hs_match($from, $subject, $received, $replyTo !== '' ? [$replyTo] : []);
        $inReply = trim((string) (jeab_inkorg_header($headers, 'In-Reply-To') ?? ''), "<> \t");
        $graphBody = (string) ($g['body']['content'] ?? '');
        $sameMail = $exact && $inReply === '' && $hsTid !== '' && jeab_inkorg_thread_has_same_mail($hsTid, $graphBody);
        if ($sameMail) {
            if ($conv !== '') {
                jeab_graph_state_update(function (array &$d) use ($conv, $hsTid) {
                    $d['conv'][$conv] = $hsTid;
                    return true;
                });
            }
            return ['ok' => true, 'duplicate' => true, 'helpscout' => true, 'thread_id' => $hsTid];
        }
        $tid = $hsTid !== '' ? $hsTid : 'g-' . substr(hash('sha256', $conv !== '' ? $conv : $mid), 0, 16);
    }
    $hsBox = function_exists('jeab_helpscout_mailbox_id') ? (string) jeab_helpscout_mailbox_id($company) : '';
    $to = implode(', ', array_map(fn($x) => (string) ($x['emailAddress']['address'] ?? ''), (array) ($g['toRecipients'] ?? [])));
    $res = jeab_inkorg_ingest([
        'message_id' => $mid,
        'from' => $from,
        'from_name' => (string) ($g['from']['emailAddress']['name'] ?? ''),
        'to' => trim(($to !== '' ? $to : $box) . ($hsBox !== '' ? ' mailbox-' . $hsBox : '')),
        'subject' => $subject,
        'date' => $received,
        'received_at' => $received,
        'headers' => $headers,
        'body_text' => (string) ($g['body']['content'] ?? ''),
        'source' => 'graph-' . $company,
        'thread_key' => $tid,
        'graph_id' => (string) ($g['id'] ?? ''),
        'graph_conversation_id' => $conv,
        'graph_mailbox' => $box,
        'backfilled' => $backfilled ? 1 : 0,
    ]);
    if (($res['ok'] ?? false) && $conv !== '') {
        // Formulärkuvert kan ha flyttats till kundens öppna ärende. Kartan ska peka dit, inte på det förberedda g-id:t.
        $bound = trim((string) ($res['thread_id'] ?? ''));
        if ($bound === '' && function_exists('jeab_inkorg_get')) {
            $stored = jeab_inkorg_get((string) ($res['id'] ?? ''));
            $bound = is_array($stored) ? trim((string) ($stored['thread_id'] ?? '')) : '';
        }
        if ($bound === '') {
            $bound = $tid;
        }
        if ($bound !== '') {
            jeab_graph_state_update(static function (array &$d) use ($conv, $bound): bool {
                if (!isset($d['conv']) || !is_array($d['conv'])) {
                    $d['conv'] = [];
                }
                $d['conv'][$conv] = $bound;

                return true;
            });
        }
    }
    if (!$auto) {
        return $res;
    }
    $ranAuto = false;
    $graphMsg = (($res['ok'] ?? false) && empty($res['duplicate']) && function_exists('jeab_inkorg_get')) ? jeab_inkorg_get((string) ($res['id'] ?? '')) : null;
    $graphTest = is_array($graphMsg) && function_exists('jeab_test_lead_fields') ? jeab_test_lead_fields($graphMsg) : ['test' => false];
    if (!empty($graphTest['test'])) {
        $res['test_lead'] = (string) ($graphTest['reason'] ?? 'test');
        if (function_exists('jeab_auto_on_message')) {
            $res['auto'] = jeab_auto_on_message((string) $res['id']);
        }
        if (($res['auto']['why'] ?? '') !== 'testlead' && function_exists('jeab_test_lead_log')) {
            jeab_test_lead_log('offert', (string) $graphTest['reason'], (string) $graphTest['email'], (string) $graphTest['phone']);
        }
        if (function_exists('jeab_test_lead_mark')) {
            jeab_test_lead_mark((string) ($res['thread_id'] ?? ''));
        }
        $ranAuto = true;
    }
    if (($res['ok'] ?? false) && empty($res['duplicate']) && !$ranAuto && function_exists('jeab_rebook_on_message')) {
        try {
            $res['rebook'] = jeab_rebook_on_message((string) $res['id']);
            if (!in_array((string) ($res['rebook']['action'] ?? ''), ['moved', 'offered', 'hold'], true) && function_exists('jeab_auto_on_message')) {
                $res['auto'] = jeab_auto_on_message((string) $res['id']);
                $ranAuto = true;
            }
        } catch (Throwable $e) {
            if (function_exists('jeab_log_app_error')) {
                $why = function_exists('jeab_rebook_safe_why') ? jeab_rebook_safe_why($e->getMessage()) : 'okänt fel';
                jeab_log_app_error('inkorg', 'Ombokning: ' . $why);
            }
        }
    }
    if (($res['ok'] ?? false) && empty($res['duplicate']) && empty($res['skipped']) && !$ranAuto && function_exists('jeab_inkorg_maybe_close_quoted')) {
        $closeTid = (string) ($res['thread_id'] ?? '');
        try {
            jeab_inkorg_maybe_close_quoted($closeTid);
            if (function_exists('jeab_inkorg_close_retry_forget')) {
                jeab_inkorg_close_retry_forget($closeTid);
            }
        } catch (Throwable $e) {
            $why = trim($e->getMessage());
            if (function_exists('jeab_log_mask_text')) {
                $why = trim(jeab_log_mask_text($why));
            }
            if ($why === '') {
                $why = 'okänt fel';
            }
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error('inkorg', 'Stängning av offertärende stoppades. ' . $why);
            }
            if (function_exists('jeab_inkorg_close_retry_remember')) {
                jeab_inkorg_close_retry_remember($closeTid, $why);
            }
        }
    }
    if (($res['ok'] ?? false) && empty($res['duplicate']) && function_exists('jeab_inkorg_close_open_for_thread')) {
        try {
            jeab_inkorg_close_open_for_thread((string) ($res['thread_id'] ?? ''));
        } catch (Throwable $e) {
            unset($e);
        }
    }
    return $res;
}

/* ---------- Webhook ---------- */

/** Graph-id från resourceData eller från resource-sökvägen. */
function jeab_graph_notification_message_id(array $n): string
{
    $id = trim((string) ($n['resourceData']['id'] ?? ''));
    if ($id !== '') {
        return $id;
    }
    $odata = (string) ($n['resourceData']['@odata.id'] ?? $n['resource'] ?? '');
    if (preg_match("/messages\\('([^']+)'\\)/i", $odata, $m) === 1) {
        return rawurldecode($m[1]);
    }
    if (preg_match('#/messages/([^/?]+)#i', $odata, $m) === 1) {
        return rawurldecode($m[1]);
    }

    return '';
}

/** Bolag från prenumerations-id, annars från mejlboxen i resource. */
function jeab_graph_notification_company(array $n, array $subs): string
{
    $sid = trim((string) ($n['subscriptionId'] ?? ''));
    if ($sid !== '') {
        foreach ($subs as $co => $s) {
            $have = trim((string) ($s['id'] ?? ''));
            if ($have !== '' && strlen($have) === strlen($sid) && hash_equals(strtolower($have), strtolower($sid))) {
                return (string) $co;
            }
        }
    }
    $hay = strtolower((string) ($n['resourceData']['@odata.id'] ?? '') . ' ' . (string) ($n['resource'] ?? ''));
    foreach (jeab_graph_poll_mailboxes() as $co => $box) {
        $box = strtolower(trim((string) $box));
        if ($box !== '' && str_contains($hay, $box)) {
            return (string) $co;
        }
    }

    return '';
}

/** Notiser från Graph. Svarar alltid snabbt; clientState måste stämma. */
function jeab_graph_notifications(array $payload): array
{
    $want = trim((string) (jeab_graph_config()['client_state'] ?? ''));
    $subs = (array) (jeab_graph_state()['subs'] ?? []);
    $out = ['ok' => true, 'accepted' => 0, 'rejected' => 0, 'auth_failed' => 0, 'jobs' => [], 'reset' => [], 'renew' => [], 'removed' => []];
    foreach ((array) ($payload['value'] ?? []) as $n) {
        if (!is_array($n)) {
            $out['rejected']++;
            continue;
        }
        $cs = trim((string) ($n['clientState'] ?? ''));
        if ($want === '' || strlen($want) !== strlen($cs) || !hash_equals($want, $cs)) {
            $out['rejected']++;
            $out['auth_failed']++;
            continue;
        }
        $company = jeab_graph_notification_company($n, $subs);
        $event = (string) ($n['lifecycleEvent'] ?? '');
        if ($event !== '') {
            if ($company === '' || $company === 'malmo') {
                $out['rejected']++;
                continue;
            }
            $out['accepted']++;
            if ($event === 'missed' || $event === 'subscriptionRemoved') {
                $out['reset'][] = $company;
            }
            if ($event === 'reauthorizationRequired' || $event === 'subscriptionRemoved') {
                $out['renew'][] = $company;
            }
            if ($event === 'subscriptionRemoved') {
                $out['removed'][] = $company;
            }
            continue;
        }
        $id = jeab_graph_notification_message_id($n);
        if ($company === '' || $company === 'malmo' || $id === '') {
            $out['rejected']++;
            continue;
        }
        $out['accepted']++;
        $out['jobs'][] = [$company, $id];
    }
    return $out;
}

/**
 * Skriver last_hook så fort en notis tagits emot, och nollställer delta vid missed/removed.
 * Intag sker senare via kön. last_hook får inte vänta på det.
 */
function jeab_graph_hook_remember(array $res): void
{
    $accepted = (int) ($res['accepted'] ?? 0);
    $rejected = (int) ($res['rejected'] ?? 0);
    $unreadable = !empty($res['unreadable']);
    $reset = array_values(array_unique(array_filter(array_map('strval', (array) ($res['reset'] ?? [])))));
    $renew = array_values(array_unique(array_filter(array_map('strval', (array) ($res['renew'] ?? [])))));
    $removed = array_values(array_unique(array_filter(array_map('strval', (array) ($res['removed'] ?? [])))));
    if ($accepted === 0 && $rejected === 0 && $reset === [] && $renew === [] && $removed === [] && !$unreadable) {
        return;
    }
    jeab_graph_state_update(function (array &$d) use ($res, $accepted, $rejected, $unreadable, $reset, $renew, $removed) {
        if ($accepted > 0 || $rejected > 0 || $unreadable) {
            $hook = [
                'at' => date('c'),
                'jobs' => count((array) ($res['jobs'] ?? [])),
                'accepted' => $accepted,
                'rejected' => $rejected,
            ];
            if ($unreadable) {
                $hook['unreadable'] = true;
            }
            $d['last_hook'] = $hook;
        }
        foreach ($reset as $co) {
            unset($d['delta'][$co]);
            $d['delta_since'][$co] = jeab_graph_delta_since_unix($d, $co);
        }
        foreach (array_unique(array_merge($renew, $removed)) as $co) {
            if (!is_array($d['subs'][$co] ?? null)) {
                $d['subs'][$co] = [];
            }
            $d['subs'][$co]['expires'] = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
            $d['subs'][$co]['checked_at'] = 0;
            if (in_array($co, $removed, true)) {
                $d['subs'][$co]['id'] = '';
            }
        }

        return true;
    });
}

/**
 * 401 bara när clientState saknas eller är fel och inget accepterades.
 * Okänt prenumerations-id är 202 så Graph inte släpper prenumerationen.
 */
function jeab_graph_hook_http_status(array $res): int
{
    if (!empty($res['unreadable'])) {
        return 400;
    }
    if ((int) ($res['auth_failed'] ?? 0) > 0 && (int) ($res['accepted'] ?? 0) === 0) {
        return 401;
    }

    return 202;
}

/**
 * Sant när ett sparat Graph-mejl är minst 10 min nyare än senaste krok, omväpning eller skapad prenumeration.
 * Saknad last_hook och saknad omväpning räknas inte: historiska mejl ska inte skapa om i en loop.
 * renewed ingår inte. Ett färskt förnyande ska inte dölja att kroken tystnat.
 */
function jeab_graph_hook_is_behind(array $state, string $co): bool
{
    $latest = jeab_graph_latest_graph_received_unix($co);
    if ($latest <= 0) {
        return false;
    }
    $hookAt = strtotime((string) ($state['last_hook']['at'] ?? '')) ?: 0;
    $sub = (array) ($state['subs'][$co] ?? []);
    $rearmed = (int) ($sub['hook_rearmed_at'] ?? 0);
    if ($hookAt <= 0 && $rearmed <= 0) {
        return false;
    }
    $born = strtotime((string) ($sub['created'] ?? '')) ?: 0;

    return $latest > max($hookAt, $rearmed, $born) + 600;
}

/* ---------- Prenumerationer + delta ---------- */

function jeab_graph_subscriptions_ensure(): array
{
    $c = jeab_graph_config();
    if (!$c) {
        return ['ok' => false, 'error' => 'Graph ej konfigurerat.'];
    }
    $exp = gmdate('Y-m-d\TH:i:s\Z', time() + 4000 * 60);
    $state = jeab_graph_state();
    $subs = (array) ($state['subs'] ?? []);
    $out = ['ok' => true, 'subs' => []];
    $wantUrl = JEAB_GRAPH_HOOK_URL;
    $wantCs = trim((string) ($c['client_state'] ?? ''));
    foreach (jeab_graph_poll_mailboxes() as $co => $box) {
        $cur = (array) ($subs[$co] ?? []);
        $curId = (string) ($cur['id'] ?? '');
        $curUrl = (string) ($cur['notificationUrl'] ?? '');
        $life = (string) ($cur['lifecycleNotificationUrl'] ?? '');
        $checked = (int) ($cur['checked_at'] ?? 0);
        $behind = jeab_graph_hook_is_behind($state, (string) $co);
        $res = null;
        // Graph byter varken notificationUrl eller lifecycleNotificationUrl. Läs den levande posten
        // när tillståndet är osäkert, så en gammal graph-hook.php-URL inte bara förnyas.
        if ($curId !== '' && ($curUrl !== $wantUrl || $life !== $wantUrl || $checked < time() - 3600)) {
            $live = jeab_graph_api('GET', 'subscriptions/' . rawurlencode($curId));
            if ($live['code'] === 200) {
                $liveUrl = trim((string) ($live['body']['notificationUrl'] ?? ''));
                if ($liveUrl !== '') {
                    $curUrl = $liveUrl;
                }
                if (array_key_exists('lifecycleNotificationUrl', (array) $live['body'])) {
                    $life = trim((string) $live['body']['lifecycleNotificationUrl']);
                }
                $liveCs = trim((string) ($live['body']['clientState'] ?? ''));
                if ($liveCs !== '' && ($wantCs === '' || strlen($liveCs) !== strlen($wantCs) || !hash_equals($wantCs, $liveCs))) {
                    $curUrl = '';
                    $life = '';
                }
            } elseif ($live['code'] === 404) {
                $curId = '';
            }
        }
        if ($behind) {
            $curUrl = '';
            $life = '';
        }
        if ($curId !== '' && $curUrl === $wantUrl && $life === $wantUrl) {
            $r = jeab_graph_api('PATCH', 'subscriptions/' . rawurlencode($curId), ['expirationDateTime' => $exp]);
            if ($r['code'] === 200) {
                $res = [
                    'id' => $curId,
                    'expires' => (string) ($r['body']['expirationDateTime'] ?? $exp),
                    'renewed' => date('c'),
                    'notificationUrl' => $wantUrl,
                    'lifecycleNotificationUrl' => $wantUrl,
                    'checked_at' => time(),
                ];
                if ((string) ($cur['created'] ?? '') !== '') {
                    $res['created'] = (string) $cur['created'];
                }
                if ((int) ($cur['hook_rearmed_at'] ?? 0) > 0) {
                    $res['hook_rearmed_at'] = (int) $cur['hook_rearmed_at'];
                }
            }
        }
        if ($res === null && $curId !== '' && ($curUrl !== $wantUrl || $life !== $wantUrl)) {
            $del = jeab_graph_api('DELETE', 'subscriptions/' . rawurlencode($curId));
            if (in_array($del['code'], [200, 202, 204, 404], true)) {
                $curId = '';
            } else {
                $res = [
                    'id' => $curId,
                    'expires' => (string) ($cur['expires'] ?? ''),
                    'notificationUrl' => $curUrl,
                    'lifecycleNotificationUrl' => $life,
                    'error' => jeab_graph_public_error($del),
                    'error_at' => date('c'),
                ];
            }
        }
        if ($res === null) {
            $r = jeab_graph_api('POST', 'subscriptions', [
                'changeType' => 'created',
                'notificationUrl' => $wantUrl,
                'lifecycleNotificationUrl' => $wantUrl,
                'resource' => "users/{$box}/mailFolders('inbox')/messages",
                'expirationDateTime' => $exp,
                'clientState' => (string) $c['client_state'],
            ]);
            if ($r['code'] === 201 && (string) ($r['body']['id'] ?? '') !== '') {
                $res = [
                    'id' => (string) ($r['body']['id'] ?? ''),
                    'expires' => (string) ($r['body']['expirationDateTime'] ?? $exp),
                    'created' => date('c'),
                    'notificationUrl' => $wantUrl,
                    'lifecycleNotificationUrl' => $wantUrl,
                    'checked_at' => time(),
                ];
                if ($behind) {
                    $res['hook_rearmed_at'] = time();
                }
            } else {
                $err = jeab_graph_public_error($r);
                $res = [
                    'id' => $curId,
                    'expires' => (string) ($cur['expires'] ?? ''),
                    'notificationUrl' => $curUrl,
                    'lifecycleNotificationUrl' => $life,
                    'error' => $err,
                    'error_at' => date('c'),
                ];
            }
        }
        $out['subs'][$co] = $res;
        $err = trim((string) ($res['error'] ?? ''));
        if ($err === '') {
            jeab_graph_state_update(function (array &$d) use ($co, $res) {
                $d['subs'][$co] = $res;
                return true;
            });
        } else {
            $prev = trim((string) ($cur['error'] ?? ''));
            if ($prev !== $err || (string) ($cur['id'] ?? '') !== (string) ($res['id'] ?? '')) {
                jeab_graph_state_update(function (array &$d) use ($co, $res) {
                    $d['subs'][$co] = $res;
                    return true;
                });
            }
            if ($prev !== $err && function_exists('jeab_log_app_error')) {
                jeab_log_app_error('inkorg', 'Graph-prenumeration ' . $co . ': ' . $err);
            }
            $out['ok'] = false;
        }
    }
    return $out;
}

/** Sant när en pollad mejlbox saknar prenumeration, har feltext, annan URL, tyst krok eller går ut inom ett dygn. */
function jeab_graph_subs_need_renew(): bool
{
    if (!jeab_graph_configured()) {
        return false;
    }
    $state = jeab_graph_state();
    $subs = (array) ($state['subs'] ?? []);
    $soon = time() + 86400;
    foreach (jeab_graph_poll_mailboxes() as $co => $box) {
        unset($box);
        $s = (array) ($subs[$co] ?? []);
        if ((string) ($s['id'] ?? '') === '' || trim((string) ($s['error'] ?? '')) !== '') {
            return true;
        }
        if ((string) ($s['notificationUrl'] ?? '') !== JEAB_GRAPH_HOOK_URL) {
            return true;
        }
        if ((string) ($s['lifecycleNotificationUrl'] ?? '') !== JEAB_GRAPH_HOOK_URL) {
            return true;
        }
        if ((int) ($s['checked_at'] ?? 0) < time() - 3600) {
            return true;
        }
        $exp = strtotime((string) ($s['expires'] ?? ''));
        if ($exp === false || $exp < $soon) {
            return true;
        }
        if (jeab_graph_hook_is_behind($state, (string) $co)) {
            return true;
        }
    }
    return false;
}

/** Delta på inkorgen per mejlbox: allt som missats av webhooken tas in (idempotent). */
function jeab_graph_delta(int $days = 0, int $maxPages = 10): array
{
    if (function_exists('jeab_pdo_hold_push')) {
        jeab_pdo_hold_push();
    }
    try {
        return jeab_graph_delta_held($days, $maxPages);
    } finally {
        if (function_exists('jeab_pdo_hold_pop')) {
            jeab_pdo_hold_pop();
        }
    }
}

function jeab_graph_delta_progress_max(string $current, string $candidate): string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return $current;
    }
    if ($current === '') {
        return $candidate;
    }
    $a = strtotime($current);
    $b = strtotime($candidate);
    if ($b === false) {
        return $current;
    }
    if ($a === false || $b > $a) {
        return $candidate;
    }

    return $current;
}

/** Tid att börja om från: nyaste sedda mejlet minus 10 min, annars senaste lyckade delta minus 10 min. */
function jeab_graph_delta_since_unix(array $state, string $co, string $progressIso = ''): int
{
    $progressIso = $progressIso !== '' ? $progressIso : (string) ($state['delta_progress'][$co] ?? '');
    $fromProgress = 0;
    $progressAt = strtotime($progressIso);
    if ($progressAt !== false && $progressAt > 0) {
        $fromProgress = $progressAt - 600;
    }
    $explicit = (int) ($state['delta_since'][$co] ?? 0);
    if ($fromProgress > 0 || $explicit > 0) {
        return max($fromProgress, $explicit);
    }
    $lastOk = (int) ($state['delta_ok_at'][$co] ?? 0);
    if ($lastOk > 0) {
        return $lastOk - 600;
    }

    return time();
}

function jeab_graph_delta_time_link(string $box, int $sinceUnix): string
{
    $since = gmdate('Y-m-d\TH:i:s\Z', $sinceUnix);

    return 'users/' . rawurlencode($box) . "/mailFolders('inbox')/messages/delta?\$select=id,receivedDateTime&\$filter=receivedDateTime+ge+{$since}";
}

function jeab_graph_tick_time_up(): bool
{
    $deadline = (float) ($GLOBALS['_JEAB_GRAPH_TICK_DEADLINE'] ?? 0);

    return $deadline > 0 && microtime(true) >= $deadline - 0.4;
}

/** Nyaste redan sparade Graph-mejlet. 0 om inkorgen saknar källan. */
function jeab_graph_latest_graph_received_unix(string $co): int
{
    $latest = 0;
    $prefix = 'graph-' . $co;
    foreach (jeab_inkorg_messages() as $m) {
        if ((string) ($m['source'] ?? '') !== $prefix) {
            continue;
        }
        $t = strtotime((string) ($m['received_at'] ?? ''));
        if ($t !== false && $t > $latest) {
            $latest = $t;
        }
    }

    return $latest;
}

function jeab_graph_list_time_link(string $box, int $sinceUnix, bool $orderBy): string
{
    $since = gmdate('Y-m-d\TH:i:s\Z', max(0, $sinceUnix));
    $q = "\$select=id,receivedDateTime&\$filter=receivedDateTime+ge+{$since}&\$top=50";
    if ($orderBy) {
        $q .= '&$orderby=receivedDateTime+asc';
    }

    return 'users/' . rawurlencode($box) . "/mailFolders('inbox')/messages?" . $q;
}

/**
 * Sparar deltacursor efter varje läst sida så ett avbrutet tick inte tappar nästa länk.
 * En tom filtrerad deltaLink flyttar inte markören till «nu».
 */
function jeab_graph_delta_store_cursor(string $co, string $deltaLink, string $nextLink, string $progress, bool $bumpNow): void
{
    if ($deltaLink !== '') {
        if ($bumpNow) {
            $progress = jeab_graph_delta_progress_max($progress, gmdate('Y-m-d\TH:i:s\Z'));
        }
        jeab_graph_state_update(function (array &$d) use ($co, $deltaLink, $progress) {
            $d['delta'][$co] = $deltaLink;
            $d['delta_ok_at'][$co] = time();
            if ($progress !== '') {
                $d['delta_progress'][$co] = $progress;
            }
            unset($d['delta_since'][$co]);

            return true;
        });

        return;
    }
    if ($nextLink === '') {
        return;
    }
    jeab_graph_state_update(function (array &$d) use ($co, $nextLink, $progress) {
        $d['delta'][$co] = $nextLink;
        if ($progress !== '') {
            $d['delta_progress'][$co] = $progress;
        }

        return true;
    });
}

/** Sidan får flytta markören bara när varje id är sparat, dubblett eller överhoppat. */
function jeab_graph_ingest_kept(array $res): bool
{
    return !empty($res['ok']);
}

/**
 * Listar inkorgen från senaste sparade Graph-mejlet minus 10 min.
 * En tom filtrerad deltaLink är inte ett bevis på att luckan är läst.
 *
 * @param array{seen:int,new:int,duplicates:int,errors:int,limited?:bool,error?:string,list_error?:string} $st
 * @return array{seen:int,new:int,duplicates:int,errors:int,limited?:bool,error?:string,list_error?:string}
 */
function jeab_graph_list_backfill(string $co, string $box, array $st): array
{
    if (!jeab_graph_configured()) {
        return $st;
    }
    $state = jeab_graph_state();
    $pendingList = (string) ($state['delta_list'][$co] ?? '');
    if ($pendingList === '' && (int) ($state['delta_listed_through'][$co] ?? 0) > 0) {
        return $st;
    }
    $cursor = (string) ($state['delta'][$co] ?? '');
    if ($pendingList === '' && $cursor !== '' && !str_contains($cursor, 'deltatoken')) {
        return $st;
    }
    $link = $pendingList;
    if ($link === '') {
        $latest = jeab_graph_latest_graph_received_unix($co);
        $link = jeab_graph_list_time_link($box, $latest > 0 ? $latest - 600 : time(), true);
    }
    $orderRetry = str_contains($link, 'orderby');
    $progress = (string) ($state['delta_progress'][$co] ?? '');
    $recoverySeconds = (float) ($GLOBALS['_JEAB_GRAPH_DELTA_RECOVERY_SECONDS'] ?? JEAB_GRAPH_DELTA_RECOVERY_SECONDS);
    $started = microtime(true);
    $pages = 0;
    while ($link !== '' && $pages < JEAB_GRAPH_DELTA_RECOVERY_PAGES) {
        if (jeab_graph_tick_time_up() || ($pages > 0 && (microtime(true) - $started) >= $recoverySeconds)) {
            $st['limited'] = true;
            if ($link !== '') {
                $keep = $link;
                $keepProgress = $progress;
                jeab_graph_state_update(function (array &$d) use ($co, $keep, $keepProgress) {
                    $d['delta_list'][$co] = $keep;
                    if ($keepProgress !== '') {
                        $d['delta_progress'][$co] = $keepProgress;
                    }

                    return true;
                });
            }
            break;
        }
        $r = jeab_graph_api('GET', $link, null, ['Prefer: odata.maxpagesize=50']);
        if ($orderRetry && (int) $r['code'] === 400 && str_contains($link, 'orderby')) {
            $latest = jeab_graph_latest_graph_received_unix($co);
            $link = jeab_graph_list_time_link($box, $latest > 0 ? $latest - 600 : time(), false);
            $orderRetry = false;
            continue;
        }
        if ((int) $r['code'] !== 200) {
            $st['list_error'] = jeab_graph_public_error($r);
            break;
        }
        $orderRetry = false;
        $pageLink = $link;
        $pageFailed = false;
        foreach ((array) ($r['body']['value'] ?? []) as $m) {
            if (!is_array($m) || (string) ($m['id'] ?? '') === '') {
                continue;
            }
            $progress = jeab_graph_delta_progress_max($progress, (string) ($m['receivedDateTime'] ?? ''));
            $st['seen']++;
            try {
                $res = jeab_graph_ingest_backfill($co, (string) $m['id'], (string) ($m['receivedDateTime'] ?? ''));
            } catch (Throwable $e) {
                $why = function_exists('jeab_throwable_log_message') ? jeab_throwable_log_message($e) : $e->getMessage();
                if (function_exists('jeab_log_mask_text')) {
                    $why = trim(jeab_log_mask_text($why));
                }
                $res = ['ok' => false, 'error' => $why !== '' ? $why : 'okänt fel'];
            }
            if (!jeab_graph_ingest_kept($res)) {
                $pageFailed = true;
                $st['errors']++;
                if (trim((string) ($st['error'] ?? '')) === '') {
                    $st['error'] = trim((string) ($res['error'] ?? '')) !== '' ? trim((string) $res['error']) : 'okänt fel';
                }
            } elseif (!empty($res['duplicate']) || !empty($res['skipped'])) {
                $st['duplicates']++;
            } else {
                $st['new']++;
            }
        }
        $pages++;
        if ($pageFailed) {
            // Misslyckat intag: behåll sidan. Nästa tick läser samma länk.
            $keep = $pageLink;
            jeab_graph_state_update(function (array &$d) use ($co, $keep) {
                $d['delta_list'][$co] = $keep;
                unset($d['delta_listed_through'][$co]);

                return true;
            });
            break;
        }
        $link = (string) ($r['body']['@odata.nextLink'] ?? '');
        $saveLink = $link;
        $saveProgress = $progress;
        $done = $saveLink === '';
        if ($done) {
            $saveProgress = jeab_graph_delta_progress_max($saveProgress, gmdate('Y-m-d\TH:i:s\Z'));
        }
        jeab_graph_state_update(function (array &$d) use ($co, $saveLink, $saveProgress, $done) {
            if ($done) {
                unset($d['delta_list'][$co]);
                $d['delta_listed_through'][$co] = time();
            } else {
                $d['delta_list'][$co] = $saveLink;
            }
            if ($saveProgress !== '') {
                $d['delta_progress'][$co] = $saveProgress;
            }

            return true;
        });
    }

    return $st;
}

function jeab_graph_delta_held(int $days, int $maxPages): array
{
    unset($days);
    $out = ['ok' => true, 'boxes' => []];
    // Bolag i Help Scout: glöm deltaläget så att en senare återkoppling börjar från «nu» (ingen bakåtfyllning).
    $off = array_diff_key(jeab_graph_mailboxes(), jeab_graph_poll_mailboxes());
    $stored = jeab_graph_state();
    $tracked = (array) ($stored['delta'] ?? [])
        + (array) ($stored['delta_ok_at'] ?? [])
        + (array) ($stored['delta_progress'] ?? [])
        + (array) ($stored['delta_since'] ?? [])
        + (array) ($stored['delta_list'] ?? [])
        + (array) ($stored['delta_listed_through'] ?? []);
    if ($off && array_intersect_key($tracked, $off)) {
        jeab_graph_state_update(function (array &$d) use ($off) {
            foreach (array_keys($off) as $co) {
                unset(
                    $d['delta'][$co],
                    $d['delta_ok_at'][$co],
                    $d['delta_progress'][$co],
                    $d['delta_since'][$co],
                    $d['delta_list'][$co],
                    $d['delta_listed_through'][$co]
                );
            }
            return true;
        });
    }
    $recoverySeconds = (float) ($GLOBALS['_JEAB_GRAPH_DELTA_RECOVERY_SECONDS'] ?? JEAB_GRAPH_DELTA_RECOVERY_SECONDS);
    foreach (jeab_graph_poll_mailboxes() as $co => $box) {
        $state = jeab_graph_state();
        $link = (string) ($state['delta'][$co] ?? '');
        $progress = (string) ($state['delta_progress'][$co] ?? '');
        if ($link === '') {
            // Första synken börjar från «nu». Efter 410/400: markör minus 10 min, annars delta_ok_at minus 10 min.
            $link = jeab_graph_delta_time_link($box, jeab_graph_delta_since_unix($state, (string) $co, $progress));
        }
        $recovery = !str_contains($link, 'deltatoken');
        $pageCap = $recovery ? max($maxPages, JEAB_GRAPH_DELTA_RECOVERY_PAGES) : $maxPages;
        $st = ['seen' => 0, 'new' => 0, 'duplicates' => 0, 'errors' => 0];
        $next = null;
        $resets = 0;
        $emptyFilterDelta = false;
        $started = microtime(true);
        $held = false;
        $p = 0;
        while ($p < $pageCap && $link !== '') {
            if (jeab_graph_tick_time_up() || ($recovery && $p > 0 && (microtime(true) - $started) >= $recoverySeconds)) {
                $st['limited'] = true;
                break;
            }
            $requested = $link;
            $r = jeab_graph_api('GET', $link, null, ['Prefer: odata.maxpagesize=50']);
            if ($r['code'] === 410 || $r['code'] === 400) {
                $st['error'] = jeab_graph_public_error($r);
                if (function_exists('jeab_log_app_error')) {
                    jeab_log_app_error('inkorg', 'Graph-delta ' . $co . ': ' . $st['error']);
                }
                $sinceUnix = 0;
                jeab_graph_state_update(function (array &$d) use ($co, $progress, &$sinceUnix) {
                    $sinceUnix = jeab_graph_delta_since_unix($d, (string) $co, $progress);
                    unset($d['delta'][$co]);
                    $d['delta_since'][$co] = $sinceUnix;
                    if ($progress !== '') {
                        $d['delta_progress'][$co] = $progress;
                    }
                    return true;
                });
                $st['reset'] = true;
                $next = null;
                if ($resets >= 1) {
                    $link = '';
                    break;
                }
                $resets++;
                $recovery = true;
                $pageCap = $p + 1 + JEAB_GRAPH_DELTA_RECOVERY_PAGES;
                $started = microtime(true);
                $link = jeab_graph_delta_time_link($box, $sinceUnix);
                $p++;
                continue;
            }
            if ($r['code'] !== 200) {
                $st['error'] = jeab_graph_public_error($r);
                if (function_exists('jeab_log_app_error')) {
                    jeab_log_app_error('inkorg', 'Graph-delta ' . $co . ': ' . $st['error']);
                }
                break;
            }
            $pageFailed = false;
            foreach ((array) ($r['body']['value'] ?? []) as $m) {
                if (!is_array($m) || isset($m['@removed']) || (string) ($m['id'] ?? '') === '') {
                    continue;
                }
                $progress = jeab_graph_delta_progress_max($progress, (string) ($m['receivedDateTime'] ?? ''));
                $st['seen']++;
                $graphId = (string) $m['id'];
                try {
                    $res = jeab_graph_ingest_backfill($co, $graphId, (string) ($m['receivedDateTime'] ?? ''));
                } catch (Throwable $e) {
                    $why = function_exists('jeab_throwable_log_message') ? jeab_throwable_log_message($e) : $e->getMessage();
                    if (function_exists('jeab_log_mask_text')) {
                        $why = trim(jeab_log_mask_text($why));
                    }
                    if ($why === '') {
                        $why = 'okänt fel';
                    }
                    $res = ['ok' => false, 'error' => $why];
                }
                if (!jeab_graph_ingest_kept($res)) {
                    $pageFailed = true;
                    $st['errors']++;
                    $why = trim((string) ($res['error'] ?? ''));
                    if ($why === '') {
                        $why = 'okänt fel';
                    }
                    if (trim((string) ($st['error'] ?? '')) === '') {
                        $st['error'] = $why;
                    }
                    if (function_exists('jeab_log_app_error')) {
                        $logWhy = function_exists('jeab_log_mask_text') ? trim(jeab_log_mask_text($why)) : $why;
                        jeab_log_app_error('inkorg', 'Graph-intag ' . $co . ': ' . ($logWhy !== '' ? $logWhy : 'okänt fel'));
                    }
                } elseif (!empty($res['duplicate']) || !empty($res['skipped'])) {
                    $st['duplicates']++;
                } else {
                    $st['new']++;
                }
            }
            $deltaLink = (string) ($r['body']['@odata.deltaLink'] ?? '');
            $nextLink = (string) ($r['body']['@odata.nextLink'] ?? '');
            if ($pageFailed) {
                // Fel på sidan: skriv inte deltaLink eller nextLink. Nästa tick tar samma sida.
                $held = true;
                break;
            }
            $link = $nextLink;
            $next = $deltaLink !== '' ? $deltaLink : $next;
            if ($deltaLink !== '' && !str_contains($requested, 'deltatoken') && (int) $st['seen'] === 0) {
                $emptyFilterDelta = true;
            } elseif ((int) $st['seen'] > 0) {
                $emptyFilterDelta = false;
            }
            if ($link !== '' && !str_contains($link, 'deltatoken')) {
                $recovery = true;
                $pageCap = max($pageCap, JEAB_GRAPH_DELTA_RECOVERY_PAGES);
            }
            $bump = $deltaLink !== '' && !$emptyFilterDelta && (int) (jeab_graph_state()['delta_listed_through'][$co] ?? 0) > 0;
            jeab_graph_delta_store_cursor((string) $co, $deltaLink, $deltaLink === '' ? $link : '', $progress, $bump);
            $p++;
        }
        if (!$held && $next) {
            $bump = !$emptyFilterDelta && (int) (jeab_graph_state()['delta_listed_through'][$co] ?? 0) > 0;
            jeab_graph_delta_store_cursor((string) $co, (string) $next, '', $progress, $bump);
        } elseif (!$held && $link !== '') {
            // Fler sidor kvar: spara nextLink och hur långt vi kom, så en utgången länk inte börjar om från samma tid.
            jeab_graph_delta_store_cursor((string) $co, '', $link, $progress, false);
        }
        $st = jeab_graph_list_backfill((string) $co, (string) $box, $st);
        $out['boxes'][$co] = $st;
    }
    return $out;
}

function jeab_graph_status_remember(array $subs, array $delta): void
{
    $boxes = isset($delta['boxes']) && is_array($delta['boxes']) ? $delta['boxes'] : [];
    $dir = jeab_inkorg_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $subsError = [];
    $subsOk = !array_key_exists('ok', $subs) || !empty($subs['ok']);
    $listed = (array) ($subs['subs'] ?? []);
    if (($subs['skipped'] ?? '') === 'färsk') {
        $listed = (array) (jeab_graph_state()['subs'] ?? []);
        $subsOk = true;
    }
    foreach ($listed as $co => $s) {
        if (!is_array($s)) {
            continue;
        }
        $err = trim((string) ($s['error'] ?? ''));
        if ($err !== '') {
            $subsError[(string) $co] = $err;
            $subsOk = false;
        }
    }
    $dailyPath = $dir . '/graph-daily.json';
    $prev = jeab_graph_json_read($dailyPath);
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
    jeab_graph_json_write($dir . '/graph-poll.json', ['at' => time(), 'result' => $boxes]);
    jeab_graph_json_write($dailyPath, [
        'date' => (string) ($prev['date'] ?? '') !== '' ? (string) $prev['date'] : $today,
        'at' => time(),
        'result' => [
            'subs_ok' => $subsOk,
            'subs_error' => $subsError,
            'delta' => $boxes,
            'malmo' => 'helpscout',
        ],
    ]);
}

/**
 * Köar Graph-id från webhooken. Bara bolag och id, ingen kundtext.
 * Sant när varje jobb finns i kön (ny rad eller samma rad sedan tidigare).
 *
 * @param list<array{0:string,1:string}|array<int,string>> $jobs
 */
/** sha256 av bolag och Graph-id. Samma par blir samma rad. */
function jeab_graph_hook_queue_key(string $company, string $messageId): string
{
    return hash('sha256', $company . "\n" . $messageId);
}

function jeab_graph_hook_pdo(): ?PDO
{
    if (!function_exists('jeab_store')) {
        return null;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return null;
    }
    if (!is_object($store) || !method_exists($store, 'pdo')) {
        return null;
    }
    $pdo = $store->pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (function_exists('jeab_ensure_graph_hook_queue')) {
        jeab_ensure_graph_hook_queue($pdo, $driver);
    }

    return $pdo;
}

function jeab_graph_hook_enqueue(array $jobs): bool
{
    $add = [];
    foreach ($jobs as $job) {
        if (!is_array($job)) {
            continue;
        }
        $co = trim((string) ($job[0] ?? ''));
        $id = trim((string) ($job[1] ?? ''));
        if ($co === '' || $id === '') {
            continue;
        }
        $add[$co . "\n" . $id] = [$co, $id];
    }
    if ($add === []) {
        return true;
    }
    $pdo = jeab_graph_hook_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $sql = $driver === 'mysql'
        ? 'INSERT IGNORE INTO graph_hook_queue (idem_key, company, message_id, queued_at) VALUES (?, ?, ?, ?)'
        : 'INSERT OR IGNORE INTO graph_hook_queue (idem_key, company, message_id, queued_at) VALUES (?, ?, ?, ?)';
    $stmt = $pdo->prepare($sql);
    $now = date('Y-m-d H:i:s');
    $check = $pdo->prepare('SELECT 1 FROM graph_hook_queue WHERE idem_key = ?');
    foreach ($add as [$co, $id]) {
        $key = jeab_graph_hook_queue_key($co, $id);
        $stmt->execute([
            $key,
            mb_substr($co, 0, 32),
            $id,
            $now,
        ]);
        $check->execute([$key]);
        if ($check->fetchColumn() === false) {
            return false;
        }
    }

    return true;
}

/** Tick tar kön. Webhooken själv hämtar inte mejlet. */
function jeab_graph_hook_drain(): int
{
    try {
        $pdo = jeab_graph_hook_pdo();
        if (!$pdo instanceof PDO) {
            return 0;
        }
        $cap = JEAB_GRAPH_HOOK_ATTEMPTS;
        $rows = $pdo->query(
            'SELECT idem_key, company, message_id, attempts FROM graph_hook_queue WHERE attempts < '
            . (int) $cap
            . ' ORDER BY queued_at, idem_key'
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return 0;
        }
        $claim = $pdo->prepare('UPDATE graph_hook_queue SET attempts = attempts + 1 WHERE idem_key = ? AND attempts = ?');
        $del = $pdo->prepare('DELETE FROM graph_hook_queue WHERE idem_key = ?');
        $done = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['idem_key'] ?? '');
            $co = trim((string) ($row['company'] ?? ''));
            $id = trim((string) ($row['message_id'] ?? ''));
            $attempts = (int) ($row['attempts'] ?? 0);
            if ($key === '' || $co === '' || $id === '' || $attempts >= $cap) {
                continue;
            }
            $claim->execute([$key, $attempts]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            try {
                $r = jeab_graph_ingest_once($co, $id);
            } catch (Throwable $e) {
                $why = function_exists('jeab_throwable_log_message') ? jeab_throwable_log_message($e) : '';
                if (function_exists('jeab_log_mask_text')) {
                    $why = trim(jeab_log_mask_text($why));
                }
                if ($why === '') {
                    $why = 'okänt fel';
                }
                if (function_exists('jeab_log_app_error')) {
                    jeab_log_app_error('inkorg', 'Graph-hook kö: ' . $why);
                }
                $r = ['ok' => false];
            }
            if (!empty($r['ok'])) {
                $del->execute([$key]);
                $done++;
                continue;
            }
            if ($attempts + 1 >= $cap && function_exists('jeab_log_app_error')) {
                jeab_log_app_error('inkorg', 'Graph-hook kö: försök slut (' . $co . ')');
            }
        }

        return $done;
    } catch (Throwable $e) {
        $why = function_exists('jeab_throwable_log_message') ? jeab_throwable_log_message($e) : '';
        if (function_exists('jeab_log_mask_text')) {
            $why = trim(jeab_log_mask_text($why));
        }
        if ($why !== '' && function_exists('jeab_log_app_error')) {
            jeab_log_app_error('inkorg', 'Graph-hook kö: ' . $why);
        }

        return 0;
    }
}

/**
 * Tick: delta och listlucka först, status direkt efter, prenumeration bara om tid finns kvar.
 * Förnyelsen är ett långt Graph-anrop. Låg den före deltat dog tickens tidsgräns innan något mejl lästes.
 *
 * @return array{subscriptions: array<string, mixed>, delta: array<string, mixed>, hook: int}
 */
function jeab_graph_mail_tick(float $deadline = 0.0): array
{
    if ($deadline > 0) {
        $GLOBALS['_JEAB_GRAPH_TICK_DEADLINE'] = $deadline;
        $left = (int) floor($deadline - microtime(true));
        if ($left > 0) {
            $GLOBALS['_JEAB_GRAPH_HTTP_TIMEOUT'] = max(1, min(25, $left));
        }
    }
    try {
        $hook = jeab_graph_hook_drain();
        $delta = jeab_graph_delta(0, 3);
        $subs = ['ok' => true, 'skipped' => 'färsk', 'subs' => (array) (jeab_graph_state()['subs'] ?? [])];
        jeab_graph_status_remember($subs, $delta);
        $remain = $deadline > 0 ? $deadline - microtime(true) : 999.0;
        if ($remain >= 3.0 && jeab_graph_subs_need_renew()) {
            if ($deadline > 0) {
                $left = (int) floor($remain);
                if ($left > 0) {
                    $GLOBALS['_JEAB_GRAPH_HTTP_TIMEOUT'] = max(1, min(25, $left));
                }
            }
            $subs = jeab_graph_subscriptions_ensure();
            jeab_graph_status_remember($subs, $delta);
        }

        return ['subscriptions' => $subs, 'delta' => $delta, 'hook' => $hook];
    } finally {
        unset($GLOBALS['_JEAB_GRAPH_TICK_DEADLINE'], $GLOBALS['_JEAB_GRAPH_HTTP_TIMEOUT']);
    }
}

function jeab_graph_sync(): array
{
    $subs = jeab_graph_subscriptions_ensure();
    $delta = jeab_graph_delta();
    jeab_graph_status_remember($subs, $delta);
    return ['subscriptions' => $subs, 'delta' => $delta];
}

/**
 * Hämtning av nya mejl. Bara sms-tick / sms-cron. Inte från en sidklick.
 *
 * @return array<string, mixed>
 */
function jeab_inkorg_mail_poll(): array
{
    if (!function_exists('jeab_graph_configured') || !jeab_graph_configured()) {
        return ['ok' => true, 'skipped' => 'graph'];
    }
    try {
        return ['ok' => true, 'graph' => jeab_graph_sync()];
    } catch (Throwable $e) {
        $why = function_exists('jeab_throwable_log_message') ? jeab_throwable_log_message($e) : $e->getMessage();
        if (function_exists('jeab_log_mask_text')) {
            $why = trim(jeab_log_mask_text($why));
        }
        if ($why === '') {
            $why = 'okänt fel';
        }
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('inkorg', 'Graph-poll: ' . $why);
        }
        return ['ok' => false, 'skipped' => 'graph', 'error' => $why];
    }
}

/** Förnya prenumerationer och delta. Bara när tick sätter JEAB_MAIL_POLL. Inte från ett klick. */
function jeab_graph_daily_schedule(): void
{
    if (empty($GLOBALS['JEAB_MAIL_POLL'])) {
        return;
    }
    if (PHP_SAPI === 'cli' || !jeab_graph_configured()) {
        return;
    }
    if (function_exists('jeab_request_skips_deferred_jobs') && jeab_request_skips_deferred_jobs()) {
        return;
    }
    $p = jeab_inkorg_dir() . '/graph-daily.json';
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
    if ((string) (jeab_graph_json_read($p)['date'] ?? '') === $today) {
        jeab_graph_poll_schedule();
        return;
    }
    $run = static function () use ($p, $today): void {
        $slot = function_exists('jeab_db_slot_acquire') ? jeab_db_slot_acquire(2) : null;
        if ($slot === null && function_exists('jeab_db_slot_acquire')) {
            return;
        }
        $lock = @fopen($p . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (function_exists('jeab_db_slot_release')) {
                jeab_db_slot_release($slot);
            }
            return;
        }
        try {
            if ((string) (jeab_graph_json_read($p)['date'] ?? '') === $today) {
                return;
            }
            if (!(function_exists('jeab_cutover_graph_write') && jeab_cutover_graph_write($p, ['date' => $today, 'at' => time()]))) {
            @file_put_contents($p, json_encode(['date' => $today, 'at' => time()]), LOCK_EX);
            }
            @set_time_limit(90);
            $r = jeab_graph_sync();
            $subErr = [];
            foreach ((array) ($r['subscriptions']['subs'] ?? []) as $co => $s) {
                if (is_array($s) && trim((string) ($s['error'] ?? '')) !== '') {
                    $subErr[(string) $co] = (string) $s['error'];
                }
            }
            $sum = [
                'subs_ok' => !empty($r['subscriptions']['ok']),
                'subs_error' => $subErr,
                'delta' => $r['delta']['boxes'] ?? [],
                'malmo' => 'helpscout',
            ];
            if (!(function_exists('jeab_cutover_graph_write') && jeab_cutover_graph_write($p, ['date' => $today, 'at' => time(), 'result' => $sum]))) {
            @file_put_contents($p, json_encode(['date' => $today, 'at' => time(), 'result' => $sum]), LOCK_EX);
            }
        } catch (Throwable) {
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            if (function_exists('jeab_db_slot_release')) {
                jeab_db_slot_release($slot);
            }
        }
    };
    if (function_exists('jeab_defer_after_response')) {
        jeab_defer_after_response($run);
        return;
    }
    register_shutdown_function($run);
}

/**
 * Reserv när webhooken inte når fram: delta högst var 5:e minut
 * (ett Graph-anrop per mejlbox när inget är nytt). Sidklick anropar den inte.
 */
function jeab_graph_poll_schedule(int $every = 300): void
{
    if (function_exists('jeab_request_skips_deferred_jobs') && jeab_request_skips_deferred_jobs()) {
        return;
    }
    $p = jeab_inkorg_dir() . '/graph-poll.json';
    if (time() - (int) (jeab_graph_json_read($p)['at'] ?? 0) < $every) {
        return;
    }
    $run = static function () use ($p, $every): void {
        $slot = function_exists('jeab_db_slot_acquire') ? jeab_db_slot_acquire(2) : null;
        if ($slot === null && function_exists('jeab_db_slot_acquire')) {
            return;
        }
        $lock = @fopen($p . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (function_exists('jeab_db_slot_release')) {
                jeab_db_slot_release($slot);
            }
            return;
        }
        try {
            if (time() - (int) (jeab_graph_json_read($p)['at'] ?? 0) < $every) {
                return;
            }
            if (!(function_exists('jeab_cutover_graph_write') && jeab_cutover_graph_write($p, ['at' => time()]))) {
            @file_put_contents($p, json_encode(['at' => time()]), LOCK_EX);
            }
            @set_time_limit(60);
            $r = jeab_graph_delta(0, 3);
            if (function_exists('jeab_malmo_hs_background')) {
                jeab_malmo_hs_background();
            }
            if (!(function_exists('jeab_cutover_graph_write') && jeab_cutover_graph_write($p, ['at' => time(), 'result' => $r['boxes'] ?? []]))) {
            @file_put_contents($p, json_encode(['at' => time(), 'result' => $r['boxes'] ?? []]), LOCK_EX);
            }
        } catch (Throwable) {
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            if (function_exists('jeab_db_slot_release')) {
                jeab_db_slot_release($slot);
            }
        }
    };
    if (function_exists('jeab_defer_after_response')) {
        jeab_defer_after_response($run);
        return;
    }
    register_shutdown_function($run);
}

function jeab_graph_status(): array
{
    $st = jeab_graph_state();
    $count = [];
    $latest = [];
    foreach (jeab_inkorg_messages() as $m) {
        $s = (string) ($m['source'] ?? '');
        if (str_starts_with($s, 'graph-')) {
            $count[$s] = ($count[$s] ?? 0) + 1;
            $latest[$s] = max($latest[$s] ?? '', (string) ($m['received_at'] ?? ''));
        }
    }
    $subs = [];
    foreach ((array) ($st['subs'] ?? []) as $co => $s) {
        if (!is_array($s)) {
            continue;
        }
        $checked = (int) ($s['checked_at'] ?? 0);
        $row = [
            'has_id' => (string) ($s['id'] ?? '') !== '',
            'expires' => (string) ($s['expires'] ?? ''),
            'notification_url' => (string) ($s['notificationUrl'] ?? ''),
            'lifecycle_url' => (string) ($s['lifecycleNotificationUrl'] ?? ''),
            'checked_at' => $checked > 0 ? date('c', $checked) : '',
        ];
        $err = trim((string) ($s['error'] ?? ''));
        if ($err !== '') {
            $row['error'] = $err;
        }
        $subs[(string) $co] = $row;
    }
    $polled = jeab_graph_poll_mailboxes();
    $companies = [];
    foreach (array_keys(jeab_graph_mailboxes()) as $co) {
        if ($co === 'malmo' || !isset($polled[$co])) {
            $companies[$co] = ['mode' => 'helpscout', 'polled' => false];
        } else {
            $companies[$co] = ['mode' => 'graph', 'polled' => true];
        }
    }
    return [
        'configured' => jeab_graph_configured(),
        'send_enabled' => jeab_inkorg_send_enabled(),
        'ingest' => array_keys(jeab_graph_ingest_mailboxes()),
        'companies' => $companies,
        'malmo_note' => 'Malmö läses via Help Scout. graph-malmo är historik och pollas inte.',
        'subs' => $subs,
        'delta_set' => array_map(fn($x) => $x !== '', (array) ($st['delta'] ?? [])),
        'delta_progress' => (array) ($st['delta_progress'] ?? []),
        'delta_ok_at' => array_map(static fn($t) => (int) $t > 0 ? date('c', (int) $t) : '', (array) ($st['delta_ok_at'] ?? [])),
        'conversations' => count((array) ($st['conv'] ?? [])),
        'messages' => $count,
        'latest_received' => $latest,
        'daily' => jeab_graph_json_read(jeab_inkorg_dir() . '/graph-daily.json'),
        'poll' => jeab_graph_json_read(jeab_inkorg_dir() . '/graph-poll.json'),
        'last_hook' => (array) ($st['last_hook'] ?? []),
    ];
}

/* ---------- Skicka (bakom brytaren) ---------- */

function jeab_graph_recips(array $list): array
{
    return array_map(fn($a) => ['emailAddress' => ['address' => $a]], array_values($list));
}

/** Svar på ett befintligt Graph-mejl (trådas i kundens klient). */
function jeab_graph_reply(string $company, string $graphId, string $to, array $cc, string $text, array $opts = []): array
{
    if (function_exists('jeab_test_lead_take_mail')) {
        $mail = jeab_test_lead_take_mail($to, $cc, $opts);
        if (!empty($mail['block_to'])) {
            return ['ok' => false, 'error' => 'test lead', 'skipped' => true, 'reason' => 'testlead'];
        }
        $cc = $mail['cc'];
        $opts = $mail['opts'];
    }
    if (function_exists('jeab_inkorg_send_held') && jeab_inkorg_send_held()) {
        return ['ok' => false, 'error' => 'omklassning skickar inte', 'sent' => 0];
    }
    if (function_exists('jeab_lead_refuse_send')) {
        $why = jeab_lead_refuse_send($to, $cc, 'graph-reply');
        if ($why !== '') {
            return ['ok' => false, 'error' => $why];
        }
    }
    if (!jeab_inkorg_send_enabled()) {
        return ['ok' => false, 'error' => 'Skicka är avstängt.'];
    }
    if (!isset(jeab_graph_ingest_mailboxes()[$company])) {
        return ['ok' => false, 'error' => 'Bolaget hanteras i Help Scout.'];
    }
    $box = jeab_graph_mailboxes()[$company] ?? '';
    if (empty($opts['attachments']) && isset($GLOBALS['_JEAB_GRAPH_ATTACHMENTS']) && is_array($GLOBALS['_JEAB_GRAPH_ATTACHMENTS'])) {
        $opts['attachments'] = $GLOBALS['_JEAB_GRAPH_ATTACHMENTS'];
    }
    $graphFiles = (isset($opts['attachments']) && is_array($opts['attachments']) && function_exists('jeab_outbound_graph_attachments'))
        ? jeab_outbound_graph_attachments($opts['attachments'])
        : [];
    unset($opts['attachments']);
    if ($box === '' || $graphId === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Fel mottagare eller bolag.'];
    }
    $gate = ['action' => 'send', 'inner' => true, 'manual_pending' => false, 'ticket' => ''];
    if (function_exists('send_guard_check')) {
        $gate = jeab_send_guard_gate('email', $to, $text, [
            'template' => (string) ($opts['guard_template'] ?? 'svar'),
            'mode' => (string) ($opts['guard_mode'] ?? 'auto'),
            'via' => (string) ($opts['guard_via'] ?? ''),
            'actor' => (string) ($opts['guard_actor'] ?? ''),
            'body_hash' => (string) ($opts['guard_body_hash'] ?? ''),
            'handler' => 'graph_reply',
            'job' => ['company' => $company, 'graph_id' => $graphId, 'to' => $to, 'cc' => $cc, 'text' => $text, 'opts' => $opts],
        ]);
        if (($gate['action'] ?? '') === 'stop') {
            $stopped = $gate['result'] ?? null;
            return is_array($stopped) ? $stopped : ['ok' => false, 'error' => 'Automatiska utskick är stoppade.'];
        }
    }
    try {
    $msg = ['toRecipients' => jeab_graph_recips([$to])];
    if ($cc || !empty($opts['strip_cc'])) {
        $msg['ccRecipients'] = jeab_graph_recips($cc);
    }
    if ($graphFiles !== []) {
        $msg['attachments'] = $graphFiles;
    }
    $r = jeab_graph_api('POST', 'users/' . rawurlencode($box) . '/messages/' . rawurlencode($graphId) . '/reply', ['message' => $msg, 'comment' => $text]);
    $replyOut = $r['code'] === 202 ? ['ok' => true, 'via' => 'graph-reply', 'from' => $box] : jeab_graph_fail('graph reply ', $r);
    return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $replyOut) : $replyOut;
    } finally {
        if (function_exists('jeab_send_guard_release')) {
            jeab_send_guard_release($gate);
        }
    }
}

function jeab_graph_send(string $company, string $to, string $subject, string $text, array $cc = [], array $opts = []): array
{
    if (function_exists('jeab_test_lead_take_mail')) {
        $mail = jeab_test_lead_take_mail($to, $cc, $opts);
        if (!empty($mail['block_to'])) {
            return ['ok' => false, 'error' => 'test lead', 'skipped' => true, 'reason' => 'testlead'];
        }
        $cc = $mail['cc'];
        $opts = $mail['opts'];
    }
    if (function_exists('jeab_inkorg_send_held') && jeab_inkorg_send_held()) {
        return ['ok' => false, 'error' => 'omklassning skickar inte', 'sent' => 0];
    }
    if (function_exists('jeab_lead_refuse_send')) {
        $why = jeab_lead_refuse_send($to, $cc, 'graph-send');
        if ($why !== '') {
            return ['ok' => false, 'error' => $why];
        }
    }
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        jeab_kontakt_side_effect_note('mail');
        return ['ok' => false, 'error' => 'Mejl är avstängt under sammanslagning och import.'];
    }
    if (!jeab_inkorg_send_enabled()) {
        return ['ok' => false, 'error' => 'Skicka är avstängt.'];
    }
    if (!isset(jeab_graph_ingest_mailboxes()[$company])) {
        return ['ok' => false, 'error' => 'Bolaget hanteras i Help Scout.'];
    }
    $box = jeab_graph_mailboxes()[$company] ?? '';
    if (empty($opts['attachments']) && isset($GLOBALS['_JEAB_GRAPH_ATTACHMENTS']) && is_array($GLOBALS['_JEAB_GRAPH_ATTACHMENTS'])) {
        $opts['attachments'] = $GLOBALS['_JEAB_GRAPH_ATTACHMENTS'];
    }
    $graphFiles = (isset($opts['attachments']) && is_array($opts['attachments']) && function_exists('jeab_outbound_graph_attachments'))
        ? jeab_outbound_graph_attachments($opts['attachments'])
        : [];
    unset($opts['attachments']);
    if ($box === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Fel mottagare eller bolag.'];
    }
    $gate = ['action' => 'send', 'inner' => true, 'manual_pending' => false, 'ticket' => ''];
    if (function_exists('send_guard_check')) {
        $gate = jeab_send_guard_gate('email', $to, $text, [
            'template' => (string) ($opts['guard_template'] ?? 'svar'),
            'mode' => (string) ($opts['guard_mode'] ?? 'auto'),
            'via' => (string) ($opts['guard_via'] ?? ''),
            'actor' => (string) ($opts['guard_actor'] ?? ''),
            'body_hash' => (string) ($opts['guard_body_hash'] ?? ''),
            'handler' => 'graph_send',
            'job' => ['company' => $company, 'to' => $to, 'subject' => $subject, 'text' => $text, 'cc' => $cc, 'opts' => $opts],
        ]);
        if (($gate['action'] ?? '') === 'stop') {
            $stopped = $gate['result'] ?? null;
            return is_array($stopped) ? $stopped : ['ok' => false, 'error' => 'Automatiska utskick är stoppade.'];
        }
    }
    try {
    $sendMessage = [
        'subject' => $subject,
        'body' => ['contentType' => 'Text', 'content' => $text],
        'toRecipients' => jeab_graph_recips([$to]),
        'ccRecipients' => jeab_graph_recips($cc),
    ];
    if ($graphFiles !== []) {
        $sendMessage['attachments'] = $graphFiles;
    }
    $r = jeab_graph_api('POST', 'users/' . rawurlencode($box) . '/sendMail', [
        'message' => $sendMessage,
        'saveToSentItems' => true,
    ]);
    $sendOut = $r['code'] === 202 ? ['ok' => true, 'via' => 'graph', 'from' => $box] : jeab_graph_fail('graph ', $r);
    return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $sendOut) : $sendOut;
    } finally {
        if (function_exists('jeab_send_guard_release')) {
            jeab_send_guard_release($gate);
        }
    }
}


/** Rubrik till hook-svar. Personnummer tas bort. Ingen brödtext. */
function jeab_graph_subject_public(string $subject): string
{
    $subject = preg_replace('/(?<!\d)(?:(?:19|20)\d{6}|\d{6})[-+]\d{4}(?!\d)/', '[pnr]', $subject) ?? $subject;
    $subject = preg_replace('/(?<!\d)(?:19|20)\d{10}(?!\d)/', '[pnr]', $subject) ?? $subject;
    $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? $subject);
    if (function_exists('mb_substr')) {
        return mb_substr($subject, 0, 120, 'UTF-8');
    }

    return substr($subject, 0, 120);
}

/**
 * Läser en mejlbox från en tidpunkt och rapporterar Graph-id som saknas i Inkorgen.
 * dry=true ändrar inget. dry=false tar in via ingest_once utan auto-offert eller utskick.
 *
 * @return array<string, mixed>
 */
function jeab_graph_catchup(string $company, string $since, bool $dry = true): array
{
    $company = trim($company);
    $box = jeab_graph_mailboxes()[$company] ?? '';
    if ($box === '') {
        throw new InvalidArgumentException('Okänt bolag.');
    }
    $ts = strtotime($since);
    if ($ts === false) {
        throw new InvalidArgumentException('Ogiltig tid.');
    }
    $sinceIso = gmdate('Y-m-d\TH:i:s\Z', $ts);
    $known = [];
    foreach (jeab_inkorg_messages() as $m) {
        $gid = trim((string) ($m['graph_id'] ?? ''));
        if ($gid !== '') {
            $known[$gid] = true;
        }
    }
    $missing = [];
    $seen = 0;
    $link = 'users/' . rawurlencode($box) . "/mailFolders('inbox')/messages?\$select=id,receivedDateTime,subject&\$filter=receivedDateTime+ge+{$sinceIso}&\$top=50";
    $started = microtime(true);
    $pages = 0;
    while ($link !== '' && $pages < JEAB_GRAPH_DELTA_RECOVERY_PAGES && (microtime(true) - $started) < JEAB_GRAPH_DELTA_RECOVERY_SECONDS) {
        $r = jeab_graph_api('GET', $link, null, ['Prefer: odata.maxpagesize=50']);
        if ((int) $r['code'] !== 200) {
            return ['ok' => false, 'dry' => $dry, 'company' => $company, 'since' => $sinceIso, 'error' => jeab_graph_public_error($r), 'missing' => $missing];
        }
        foreach ((array) ($r['body']['value'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $seen++;
            $id = trim((string) ($m['id'] ?? ''));
            if ($id === '' || isset($known[$id])) {
                continue;
            }
            $received = (string) ($m['receivedDateTime'] ?? '');
            $missing[] = [
                'id' => $id,
                'received' => $received,
                'subject' => jeab_graph_subject_public((string) ($m['subject'] ?? '')),
                'offer' => false,
                'backfilled' => 1,
            ];
        }
        $link = (string) ($r['body']['@odata.nextLink'] ?? '');
        $pages++;
    }
    usort($missing, static function (array $a, array $b): int {
        return strcmp((string) ($a['received'] ?? ''), (string) ($b['received'] ?? ''));
    });
    $ingested = [];
    if (!$dry) {
        foreach ($missing as $row) {
            $res = jeab_graph_ingest_once($company, (string) $row['id'], false, true);
            $ingested[] = [
                'id' => (string) $row['id'],
                'ok' => !empty($res['ok']),
                'duplicate' => !empty($res['duplicate']),
                'skipped' => (string) ($res['skipped'] ?? ''),
                'auto' => false,
                'backfilled' => 1,
                'offer' => false,
            ];
        }
    }
    $noOffer = [];
    foreach (jeab_inkorg_messages() as $m) {
        if ((string) ($m['source'] ?? '') !== 'graph-' . $company || (int) ($m['backfilled'] ?? 0) !== 1) {
            continue;
        }
        $rec = (string) ($m['received_at'] ?? '');
        $recTs = strtotime($rec);
        if ($recTs === false || $recTs < $ts) {
            continue;
        }
        $noOffer[] = [
            'id' => (string) ($m['graph_id'] ?? ''),
            'received' => $rec,
            'subject' => jeab_graph_subject_public((string) ($m['subject'] ?? '')),
            'backfilled' => 1,
            'offer' => false,
        ];
    }
    usort($noOffer, static function (array $a, array $b): int {
        return strcmp((string) ($a['received'] ?? ''), (string) ($b['received'] ?? ''));
    });

    return [
        'ok' => true,
        'dry' => $dry,
        'company' => $company,
        'since' => $sinceIso,
        'seen' => $seen,
        'missing' => $missing,
        'no_offer' => $noOffer,
        'ingested' => $ingested,
    ];
}

/**
 * Engångsstädning: Graph-ärenden som bakåtfylldes före övergången (redan hanterade i Help Scout)
 * flyttas till papperskorgen. Behåller ärenden med något mejl efter $cutover, annan källa än Graph,
 * skickade/godkända svar eller mejl från egna kontakt@-adresser. dry=true ändrar inget.
 */
function jeab_graph_cutover_cleanup(string $cutover, bool $dry = true): array
{
    $cut = strtotime($cutover);
    if ($cut === false) {
        throw new InvalidArgumentException('Ogiltig tid.');
    }
    $own = array_map('strtolower', array_values(jeab_graph_mailboxes()));
    $meta = jeab_inkorg_threads_meta();
    $out = ['dry' => $dry, 'cutover' => date('c', $cut), 'remove' => [], 'kept' => [], 'before' => [], 'remove_count' => []];
    foreach (jeab_inkorg_threads() as $t) {
        if (!empty($t['deleted'])) {
            continue;
        }
        $co = (string) $t['company'];
        $out['before'][$co] = ($out['before'][$co] ?? 0) + 1;
        $srcs = array_unique(array_map(fn($m) => (string) ($m['source'] ?? ''), $t['messages']));
        $allGraph = !array_filter($srcs, fn($s) => !str_starts_with($s, 'graph-'));
        if (!$allGraph) {
            continue;
        }
        $why = [];
        foreach ($t['messages'] as $m) {
            if (strtotime((string) ($m['received_at'] ?? '')) >= $cut) {
                $why['efter_cutover'] = true;
            }
            if (in_array(strtolower((string) ($m['from'] ?? '')), $own, true)) {
                $why['egen_avsandare'] = true;
            }
        }
        foreach ((array) ($meta[$t['id']]['drafts'] ?? []) as $d) {
            if (!empty($d['sent']) || in_array((string) ($d['state'] ?? ''), ['sent', 'approved'], true)) {
                $why['skickat_svar'] = true;
            }
        }
        $row = ['id' => $t['id'], 'company' => $co, 'msgs' => count($t['messages']), 'status' => $t['status'],
            'last' => (string) ($t['last']['received_at'] ?? ''), 'subject' => mb_substr((string) ($t['first']['subject'] ?? ''), 0, 60)];
        if ($why) {
            $out['kept'][] = $row + ['why' => array_keys($why)];
            continue;
        }
        if (function_exists('jeab_lead_trash_protected') && jeab_lead_trash_protected($t)) {
            $out['kept'][] = $row + ['why' => ['lead_med_produkt']];
            continue;
        }
        $out['remove'][] = $row;
        $out['remove_count'][$co] = ($out['remove_count'][$co] ?? 0) + 1;
    }
    if (!$dry && $out['remove']) {
        $out['backup'] = 'sql';
        $ids = array_column($out['remove'], 'id');
        jeab_inkorg_locked('threads', function (?array $d) use ($ids) {
            $d = $d ?? ['threads' => []];
            foreach ($ids as $id) {
                $cur = (array) ($d['threads'][$id] ?? ['status' => 'open', 'tags' => []]);
                $cur['deleted_at'] = date('c');
                $cur['deleted_by'] = 'graph-cutover';
                $cur['updated_at'] = date('c');
                $d['threads'][$id] = $cur;
            }
            return [count($ids), $d];
        });
    }
    return $out;
}
