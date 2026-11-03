<?php

declare(strict_types=1);

require_once __DIR__ . '/test_lead_address.php';

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Läs Wix Forms spamrapporter. Inget skrivs till Wix.
 * Nyckel och sajt-id läses från privat/ eller data/.private/, och skrivs aldrig ut.
 * Tick var 30:e minut lägger en intern inkorgsanteckning när domänen inte är en testdomän.
 * Inget mejl till kunder. Sedda rapport-id ligger i MySQL (tabellen skapas en gång
 * när settings-flaggan wix_spam_table saknas). Ingen SQLite-fil.
 */

const JEAB_WIX_SPAM_EVERY = 1800;
const JEAB_WIX_SPAM_NAMESPACE = 'wix.form_app.form';
const JEAB_WIX_SPAM_FORMS = '/form-schema-service/v4/forms/query';
const JEAB_WIX_SPAM_REPORTS = '/form-spam-submission-report-service/v4/form-spam-submission-reports/namespace/query';

/** @return array<string, string> site-id => jarfalla|malmo */
function jeab_wix_spam_sites(): array
{
    $override = $GLOBALS['_JEAB_WIX_SPAM_SITES'] ?? null;
    if (is_array($override) && $override !== []) {
        return jeab_wix_spam_site_map($override);
    }
    $fromFile = jeab_wix_spam_sites_file();
    if ($fromFile !== []) {
        return $fromFile;
    }
    if (defined('JEAB_WEBSHOP_SITES') && is_array(JEAB_WEBSHOP_SITES)) {
        return jeab_wix_spam_site_map(JEAB_WEBSHOP_SITES);
    }
    return [];
}

/** @param array<mixed, mixed> $raw @return array<string, string> */
function jeab_wix_spam_site_map(array $raw): array
{
    $out = [];
    foreach ($raw as $id => $company) {
        $id = strtolower(trim((string) $id));
        $co = function_exists('jeab_normalize_company') ? jeab_normalize_company($company) : null;
        if (preg_match('/^[0-9a-f-]{36}$/', $id) !== 1 || !in_array($co, ['jarfalla', 'malmo'], true)) {
            continue;
        }
        $out[$id] = $co;
    }
    return $out;
}

/** @return array<string, string> */
function jeab_wix_spam_sites_file(): array
{
    foreach (jeab_wix_spam_site_files() as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        $parsed = json_decode((string) file_get_contents($path), true);
        if (!is_array($parsed)) {
            continue;
        }
        $sites = $parsed['sites'] ?? null;
        if (!is_array($sites)) {
            continue;
        }
        $map = jeab_wix_spam_site_map($sites);
        if ($map !== []) {
            return $map;
        }
    }
    return [];
}

/** @return list<string> */
function jeab_wix_spam_site_files(): array
{
    $one = $GLOBALS['_JEAB_WIX_SPAM_SITE_FILE'] ?? null;
    if (is_string($one) && $one !== '') {
        return [$one];
    }
    $files = [];
    if (defined('JEAB_ROOT')) {
        $root = realpath(JEAB_ROOT);
        if ($root === false) {
            $root = JEAB_ROOT;
        }
        $files[] = dirname($root) . '/privat/wix-sites.json';
        $files[] = dirname($root) . '/privat/.wix-api.json';
    }
    if (function_exists('jeab_webshop_secret_path')) {
        $files[] = jeab_webshop_secret_path('wix-api.json');
    }
    return $files;
}

function jeab_wix_spam_label(string $company): string
{
    if (function_exists('jeab_company_catalog')) {
        $row = jeab_company_catalog()[$company] ?? null;
        if (is_array($row) && trim((string) ($row['label'] ?? '')) !== '') {
            return (string) $row['label'];
        }
    }
    return $company === 'malmo' ? 'Malmö' : 'Järfälla';
}

/** @return array<string, string> */
function jeab_wix_spam_pick(string $site): array
{
    $map = jeab_wix_spam_sites();
    $site = trim($site);
    if ($site === '') {
        return $map;
    }
    if (strlen($site) > 40) {
        return [];
    }
    $co = function_exists('jeab_normalize_company') ? jeab_normalize_company($site) : null;
    if ($co !== null) {
        return array_filter($map, static fn(string $company): bool => $company === $co);
    }
    $needle = strtolower($site);
    $hit = [];
    foreach ($map as $id => $company) {
        if ($id === $needle || str_starts_with($id, $needle)) {
            $hit[$id] = $company;
        }
    }
    return $hit;
}

function jeab_wix_spam_hours(mixed $raw): int
{
    if ($raw === null || $raw === '') {
        return 48;
    }
    if (is_string($raw) && preg_match('/^\d{1,4}$/', trim($raw)) !== 1) {
        return 0;
    }
    if (!is_int($raw) && !is_float($raw) && !is_string($raw)) {
        return 0;
    }
    $n = (int) $raw;
    if ($n < 1 || $n > 168) {
        return 0;
    }
    return $n;
}

/**
 * Bara de två dokumenterade query-anropen. GET/DELETE och andra sökvägar når inte Wix.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function jeab_wix_spam_budget(): ?float
{
    $given = $GLOBALS['_JEAB_WIX_SPAM_DEADLINE'] ?? null;
    if (is_int($given) || is_float($given)) {
        return (float) $given;
    }
    return null;
}

function jeab_wix_spam_query(string $siteId, string $path, array $body): array
{
    if (!in_array($path, [JEAB_WIX_SPAM_FORMS, JEAB_WIX_SPAM_REPORTS], true)) {
        throw new RuntimeException('fel');
    }
    if (!function_exists('jeab_webshop_wix_request')) {
        throw new RuntimeException('fel');
    }
    $budget = jeab_wix_spam_budget();
    $prev = $GLOBALS['_JEAB_WIX_TIMEOUT'] ?? null;
    if ($budget !== null) {
        $left = $budget - microtime(true);
        if ($left < 0.2) {
            throw new RuntimeException('tid');
        }
        $GLOBALS['_JEAB_WIX_TIMEOUT'] = max(1, min(10, (int) ceil($left)));
    }
    try {
        $data = jeab_webshop_wix_request($siteId, 'POST', $path, $body);
    } finally {
        if ($prev === null) {
            unset($GLOBALS['_JEAB_WIX_TIMEOUT']);
        } else {
            $GLOBALS['_JEAB_WIX_TIMEOUT'] = $prev;
        }
    }
    return is_array($data) ? $data : [];
}

/**
 * @param array<string, mixed> $query
 * @return list<array<string, mixed>>
 */
function jeab_wix_spam_pages(string $siteId, string $path, array $query, string $listKey, int $since): array
{
    $rows = [];
    $cursor = '';
    for ($page = 0; $page < 3; $page++) {
        $paging = ['limit' => 50];
        if ($cursor !== '') {
            $paging['cursor'] = $cursor;
        }
        $payload = $cursor === ''
            ? ['query' => $query + ['cursorPaging' => $paging]]
            : ['query' => ['cursorPaging' => $paging]];
        $data = jeab_wix_spam_query($siteId, $path, $payload);
        $batch = [];
        foreach ((array) ($data[$listKey] ?? []) as $row) {
            if (is_array($row)) {
                $batch[] = $row;
            }
        }
        $rows = array_merge($rows, $batch);
        if ($listKey === 'formSpamSubmissionReports' && jeab_wix_spam_page_ended($batch, $since)) {
            break;
        }
        $meta = is_array($data['pagingMetadata'] ?? null)
            ? $data['pagingMetadata']
            : (is_array($data['metadata'] ?? null) ? $data['metadata'] : []);
        $next = '';
        if (is_array($meta['cursors'] ?? null)) {
            $next = trim((string) ($meta['cursors']['next'] ?? ''));
        }
        if (empty($meta['hasNext']) || $next === '') {
            break;
        }
        $cursor = $next;
    }
    return $rows;
}

/** @param list<array<string, mixed>> $batch */
function jeab_wix_spam_page_ended(array $batch, int $since): bool
{
    if ($batch === []) {
        return false;
    }
    $last = $batch[count($batch) - 1];
    $at = jeab_wix_spam_stamp($last);
    return $at > 0 && $at < $since;
}

/** @param array<string, mixed> $report */
function jeab_wix_spam_stamp(array $report): int
{
    foreach (['reportedDate', 'createdDate'] as $key) {
        $raw = trim((string) ($report[$key] ?? ''));
        if ($raw === '') {
            continue;
        }
        $at = strtotime($raw);
        if ($at !== false) {
            return $at;
        }
    }
    return 0;
}

function jeab_wix_spam_form_name(array $form): string
{
    $name = trim((string) ($form['name'] ?? ''));
    if ($name === '' && is_array($form['properties'] ?? null)) {
        $name = trim((string) ($form['properties']['name'] ?? ''));
    }
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    if ($name === '') {
        return 'okänt formulär';
    }
    return mb_substr($name, 0, 120);
}

/** @param array<string, mixed> $report */
function jeab_wix_spam_email(array $report): string
{
    $subs = $report['submissions'] ?? null;
    if (is_string($subs)) {
        $decoded = json_decode($subs, true);
        $subs = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($subs)) {
        return '';
    }
    $preferred = [];
    $other = [];
    foreach ($subs as $key => $value) {
        $found = [];
        jeab_wix_spam_walk_emails($value, $found);
        if (is_string($key) && preg_match('/e-?post|email|mail/i', $key) === 1) {
            $preferred = array_merge($preferred, $found);
        } else {
            $other = array_merge($other, $found);
        }
    }
    $list = $preferred !== [] ? $preferred : $other;
    return $list[0] ?? '';
}

/** @param list<string> $out */
function jeab_wix_spam_walk_emails(mixed $value, array &$out, int $depth = 0): void
{
    if ($depth > 6 || count($out) > 4) {
        return;
    }
    if (is_string($value)) {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $m) === 1) {
            $email = strtolower($m[0]);
            if (function_exists('jeab_test_lead_email_address')) {
                $email = jeab_test_lead_email_address($email);
            }
            if ($email !== '') {
                $out[] = $email;
            }
        }
        return;
    }
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $item) {
        jeab_wix_spam_walk_emails($item, $out, $depth + 1);
    }
}

function jeab_wix_spam_domain(string $email): string
{
    $email = strtolower(trim($email));
    $at = strrchr($email, '@');
    if ($at === false) {
        return '';
    }
    return trim(substr($at, 1), ". \t");
}

function jeab_wix_spam_test_domain(string $domain): bool
{
    $domain = strtolower(trim($domain));
    if ($domain === '') {
        return false;
    }
    $labels = array_values(array_filter(explode('.', $domain), static fn(string $label): bool => $label !== ''));
    $tld = (string) ($labels[count($labels) - 1] ?? '');
    if ($tld === 'test' || $tld === 'invalid') {
        return true;
    }
    if (in_array('test', $labels, true)) {
        return true;
    }
    foreach (['example.com', 'example.org', 'example.net', 'example.se', jeab_test_lead_domain()] as $known) {
        if ($domain === $known || str_ends_with($domain, '.' . $known)) {
            return true;
        }
    }
    return false;
}

function jeab_wix_spam_mask(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return '—';
    }
    if (function_exists('jeab_test_lead_mask_email')) {
        $masked = jeab_test_lead_mask_email($email);
        if ($masked !== '') {
            return $masked;
        }
    }
    $local = (string) strstr($email, '@', true);
    $domain = jeab_wix_spam_domain($email);
    $first = $local === '' ? '' : mb_substr($local, 0, 1);
    return $first . '***@' . $domain;
}

function jeab_wix_spam_redact(string $text): string
{
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '***', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/** @param array<string, mixed> $report */
function jeab_wix_spam_reason(array $report): string
{
    $reason = trim((string) ($report['reportReason'] ?? ''));
    if ($reason === '') {
        $reason = 'okänd';
    }
    $extra = jeab_wix_spam_redact((string) ($report['reportReasonDescription'] ?? ''));
    if ($extra !== '' && $extra !== $reason) {
        $reason .= ' (' . mb_substr($extra, 0, 80) . ')';
    }
    return mb_substr($reason, 0, 140);
}

function jeab_wix_spam_when(int $at): string
{
    if ($at <= 0) {
        return '—';
    }
    $dt = (new DateTimeImmutable('@' . $at))->setTimezone(new DateTimeZone('Europe/Stockholm'));
    return $dt->format('Y-m-d H:i');
}

/**
 * @return list<array{id: string, company: string, label: string, form: string, time: string, at: int, reason: string, domain: string, masked: string, test_domain: bool}>
 */
function jeab_wix_spam_site(string $siteId, string $company, int $hours, int $now): array
{
    $since = $now - ($hours * 3600);
    $forms = jeab_wix_spam_pages($siteId, JEAB_WIX_SPAM_FORMS, [
        'filter' => ['namespace' => ['$eq' => JEAB_WIX_SPAM_NAMESPACE]],
    ], 'forms', $since);
    $names = [];
    $rows = [];
    foreach ($forms as $form) {
        $formId = trim((string) ($form['id'] ?? $form['_id'] ?? ''));
        if ($formId === '') {
            continue;
        }
        $names[$formId] = jeab_wix_spam_form_name($form);
        $reports = jeab_wix_spam_pages($siteId, JEAB_WIX_SPAM_REPORTS, [
            'filter' => [
                'namespace' => ['$eq' => JEAB_WIX_SPAM_NAMESPACE],
                'formId' => ['$eq' => $formId],
            ],
            'sort' => [['fieldName' => 'reportedDate', 'order' => 'DESC']],
        ], 'formSpamSubmissionReports', $since);
        foreach ($reports as $report) {
            $at = jeab_wix_spam_stamp($report);
            if ($at > 0 && $at < $since) {
                continue;
            }
            $email = jeab_wix_spam_email($report);
            $domain = jeab_wix_spam_domain($email);
            $reason = jeab_wix_spam_reason($report);
            $id = trim((string) ($report['id'] ?? ''));
            if ($id === '') {
                $id = substr(hash('sha256', $formId . '|' . $at . '|' . $reason . '|' . $domain), 0, 32);
            }
            $rows[] = [
                'id' => $id,
                'company' => $company,
                'label' => jeab_wix_spam_label($company),
                'form' => $names[$formId],
                'time' => jeab_wix_spam_when($at),
                'at' => $at,
                'reason' => $reason,
                'domain' => $domain,
                'masked' => jeab_wix_spam_mask($email),
                'test_domain' => jeab_wix_spam_test_domain($domain),
            ];
        }
    }
    usort($rows, static fn(array $a, array $b): int => ($b['at'] <=> $a['at']));
    return $rows;
}

/**
 * @param array<string, string> $sites
 * @return array{rows: list<array<string, mixed>>, failed: list<string>}
 */
function jeab_wix_spam_collect(array $sites, int $hours, ?int $now = null): array
{
    $now = $now ?? jeab_wix_spam_now();
    $rows = [];
    $failed = [];
    foreach ($sites as $siteId => $company) {
        try {
            $rows = array_merge($rows, jeab_wix_spam_site((string) $siteId, (string) $company, $hours, $now));
        } catch (Throwable) {
            $failed[] = (string) $company;
        }
    }
    return ['rows' => $rows, 'failed' => $failed];
}

/** @param array{rows: list<array<string, mixed>>, failed: list<string>} $found */
function jeab_wix_spam_text(array $found, array $sites): string
{
    $by = ['jarfalla' => [], 'malmo' => []];
    foreach ($found['rows'] as $row) {
        $co = (string) ($row['company'] ?? '');
        if (isset($by[$co])) {
            $by[$co][] = $row;
        }
    }
    $order = [];
    foreach ($sites as $company) {
        if (!in_array($company, $order, true)) {
            $order[] = $company;
        }
    }
    $blocks = [];
    foreach ($order as $company) {
        $lines = [jeab_wix_spam_label($company)];
        if (in_array($company, $found['failed'], true)) {
            $lines[] = 'fel';
        } elseif ($by[$company] === []) {
            $lines[] = 'inga';
        } else {
            foreach ($by[$company] as $row) {
                $lines[] = (string) $row['form'] . ' | ' . (string) $row['time'] . ' | ' . (string) $row['reason'] . ' | ' . (string) $row['masked'];
            }
        }
        $blocks[] = implode("\n", $lines);
    }
    if ($blocks === []) {
        return "inga\n";
    }
    return implode("\n\n", $blocks) . "\n";
}

/** @param array<string, mixed> $input @return array{status: int, text: string} */
function jeab_wix_spam_dispatch(array $input): array
{
    $hours = jeab_wix_spam_hours($input['since_hours'] ?? null);
    if ($hours === 0) {
        return ['status' => 400, 'text' => "since_hours ska vara 1–168.\n"];
    }
    $asked = trim((string) ($input['site'] ?? ''));
    $sites = jeab_wix_spam_pick($asked);
    if ($asked !== '' && $sites === []) {
        return ['status' => 400, 'text' => "Okänd sajt.\n"];
    }
    if ($sites === []) {
        return ['status' => 200, 'text' => "inga\n"];
    }
    $found = jeab_wix_spam_collect($sites, $hours);
    if ($found['rows'] === [] && count($found['failed']) === count($sites)) {
        return ['status' => 502, 'text' => "Wix svarade inte.\n"];
    }
    return ['status' => 200, 'text' => jeab_wix_spam_text($found, $sites)];
}

function jeab_wix_spam_now(): int
{
    $frozen = $GLOBALS['_JEAB_WIX_SPAM_NOW'] ?? null;
    if (is_int($frozen)) {
        return $frozen;
    }
    return time();
}

function jeab_wix_spam_is_mysql(PDO $pdo): bool
{
    try {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    } catch (Throwable) {
        return false;
    }
}

function jeab_wix_spam_pdo(): ?PDO
{
    $injected = $GLOBALS['_JEAB_WIX_SPAM_PDO'] ?? null;
    if ($injected instanceof PDO) {
        if (!jeab_wix_spam_is_mysql($injected)) {
            return null;
        }
        try {
            jeab_wix_spam_install($injected);
        } catch (Throwable) {
            return null;
        }
        return $injected;
    }
    if (!function_exists('jeab_store')) {
        return null;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return null;
    }
    if (!$store instanceof JeabPdoStore) {
        return null;
    }
    try {
        $pdo = $store->pdo();
    } catch (Throwable) {
        return null;
    }
    if (!$pdo instanceof PDO || !jeab_wix_spam_is_mysql($pdo)) {
        return null;
    }
    try {
        jeab_wix_spam_install($pdo);
    } catch (Throwable) {
        return null;
    }
    return $pdo;
}

/**
 * Skapar tabellen en gång och sätter settings-flaggan wix_spam_table.
 * När flaggan är 1 körs ingen DDL. Bara MySQL.
 */
function jeab_wix_spam_install(PDO $pdo): void
{
    static $done = [];
    $id = spl_object_id($pdo);
    if (isset($done[$id]) || !jeab_wix_spam_is_mysql($pdo)) {
        return;
    }
    if (function_exists('jeab_settings_value') && jeab_settings_value($pdo, 'mysql', 'wix_spam_table') === '1') {
        $done[$id] = true;
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    try {
        $pdo->query('SELECT 1 FROM settings LIMIT 1');
    } catch (Throwable) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                `key` VARCHAR(64) NOT NULL,
                value TEXT NOT NULL,
                PRIMARY KEY (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS wix_spam_seen (
            report_id VARCHAR(80) NOT NULL,
            seen_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    if (function_exists('jeab_settings_put')) {
        jeab_settings_put($pdo, 'mysql', 'wix_spam_table', '1');
    }
    $done[$id] = true;
}

function jeab_wix_spam_last(PDO $pdo): int
{
    if (!function_exists('jeab_settings_value')) {
        return 0;
    }
    $value = jeab_settings_value($pdo, 'mysql', 'wix_spam_ran');
    return is_string($value) ? (int) $value : 0;
}

function jeab_wix_spam_mark_ran(PDO $pdo, int $now): void
{
    if (function_exists('jeab_settings_put')) {
        jeab_settings_put($pdo, 'mysql', 'wix_spam_ran', (string) $now);
    }
}

function jeab_wix_spam_claim(PDO $pdo, string $id): bool
{
    $id = mb_substr(trim($id), 0, 80);
    if ($id === '') {
        return false;
    }
    try {
        $pdo->prepare('INSERT INTO wix_spam_seen (report_id, seen_at) VALUES (?, ?)')->execute([
            $id,
            jeab_wix_spam_now(),
        ]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function jeab_wix_spam_release(PDO $pdo, string $id): void
{
    $id = mb_substr(trim($id), 0, 80);
    if ($id === '') {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM wix_spam_seen WHERE report_id = ?')->execute([$id]);
    } catch (Throwable) {
    }
}

/** @param array<string, mixed> $row */
function jeab_wix_spam_notice(array $row): void
{
    if (!function_exists('jeab_inkorg_ingest') || !function_exists('jeab_inkorg_add_draft')) {
        return;
    }
    $company = (string) ($row['company'] ?? '') === 'malmo' ? 'malmo' : 'jarfalla';
    $from = $company === 'malmo' ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test';
    $label = (string) ($row['label'] ?? jeab_wix_spam_label($company));
    $board = 'wix-spam-' . $company;
    $type = 'Information';
    $seed = jeab_inkorg_ingest([
        'message_id' => $board . '@flowstack.example.test',
        'from' => $from,
        'from_name' => 'Wix',
        'to' => $from,
        'subject' => 'Wix-spam ' . $label,
        'body' => 'Spamrapporter från Wix Forms. Inget skickas till kunder.',
        'received_at' => date('c'),
        'source' => 'wix-spam',
        'internal_note' => true,
        'company' => $company,
        'thread_key' => $board,
        'preset_labels' => [
            'type' => $type,
            'action' => function_exists('jeab_inkorg_derived_action') ? jeab_inkorg_derived_action($type) : 'arkivera',
            'priority' => function_exists('jeab_inkorg_default_priority') ? jeab_inkorg_default_priority($type) : 4,
            'confidence' => 100,
            'source' => 'wix-spam',
            'reason' => 'Wix-spam',
            'needs_review' => true,
        ],
    ]);
    $tid = (string) ($seed['thread_id'] ?? '');
    if ($tid === '' && function_exists('jeab_inkorg_get')) {
        $saved = jeab_inkorg_get((string) ($seed['id'] ?? ''));
        $tid = is_array($saved) ? (string) ($saved['thread_id'] ?? '') : '';
    }
    if ($tid === '') {
        $tid = $board;
    }
    $body = $label . ': ' . (string) ($row['form'] ?? '') . ', ' . (string) ($row['time'] ?? '') . ', ' . (string) ($row['reason'] ?? '') . ', ' . (string) ($row['masked'] ?? '—');
    jeab_inkorg_add_draft($tid, ['kind' => 'note', 'body' => $body], 'Wix', 'wix-spam');
}

/** @return array{ok: bool, count: int, skipped?: string, error?: string} */
function jeab_wix_spam_tick(): array
{
    $given = $GLOBALS['_JEAB_WIX_SPAM_DEADLINE'] ?? null;
    $own = !is_int($given) && !is_float($given);
    if ($own) {
        $GLOBALS['_JEAB_WIX_SPAM_DEADLINE'] = microtime(true) + 10;
    }
    try {
        return jeab_wix_spam_tick_work();
    } catch (Throwable) {
        return ['ok' => false, 'count' => 0, 'error' => 'fel'];
    } finally {
        if ($own) {
            unset($GLOBALS['_JEAB_WIX_SPAM_DEADLINE']);
        }
    }
}

/** @return array{ok: bool, count: int, skipped?: string, error?: string} */
function jeab_wix_spam_tick_work(): array
{
    $pdo = jeab_wix_spam_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => true, 'count' => 0, 'skipped' => 'sql'];
    }
    $now = jeab_wix_spam_now();
    $last = jeab_wix_spam_last($pdo);
    if ($last > 0 && ($now - $last) < JEAB_WIX_SPAM_EVERY) {
        return ['ok' => true, 'count' => 0, 'skipped' => 'vantar'];
    }
    $sites = jeab_wix_spam_sites();
    if ($sites === []) {
        return ['ok' => true, 'count' => 0, 'skipped' => 'saknas'];
    }
    $pdo = null;
    if (function_exists('jeab_pdo_release_if_idle')) {
        jeab_pdo_release_if_idle();
    }
    try {
        $found = jeab_wix_spam_collect($sites, 48, $now);
    } catch (Throwable) {
        return ['ok' => false, 'count' => 0, 'error' => 'fel'];
    }
    if ($found['rows'] === [] && count($found['failed']) === count($sites)) {
        return ['ok' => false, 'count' => 0, 'error' => 'fel'];
    }
    $pdo = jeab_wix_spam_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'count' => 0, 'error' => 'fel'];
    }
    $flagged = 0;
    foreach ($found['rows'] as $row) {
        if (!empty($row['test_domain']) || trim((string) ($row['domain'] ?? '')) === '') {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if (!jeab_wix_spam_claim($pdo, $id)) {
            continue;
        }
        try {
            jeab_wix_spam_notice($row);
            $flagged++;
        } catch (Throwable) {
            jeab_wix_spam_release($pdo, $id);
        }
    }
    if ($found['failed'] === []) {
        jeab_wix_spam_mark_ran($pdo, $now);
    }
    return ['ok' => true, 'count' => $flagged];
}
