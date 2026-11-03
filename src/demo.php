<?php

declare(strict_types=1);

/**
 * Demoläge. Av som standard.
 * På när config['demo'] === true eller env JEAB_DEMO=1.
 * Inget bolag, ingen domän och ingen mottagare är hårdkodad här.
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit;
}

function jeab_demo_env_on(): bool
{
    $value = getenv('JEAB_DEMO');
    if (!is_string($value)) {
        return false;
    }
    $value = strtolower(trim($value));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/** @return array<string, mixed> */
function jeab_demo_config_array(): array
{
    if (function_exists('jeab_config')) {
        $config = jeab_config();

        return is_array($config) ? $config : [];
    }
    $root = dirname(__DIR__);
    $data = $root . '/data/.private';
    if (!empty($GLOBALS['_JEAB_DATA_DIR']) && is_string($GLOBALS['_JEAB_DATA_DIR'])) {
        $data = $GLOBALS['_JEAB_DATA_DIR'];
    }
    foreach ([$root . '/data/config.php', $root . '/config.php', $data . '/config.php'] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $loaded = require $path;

        return is_array($loaded) ? $loaded : [];
    }

    return [];
}

function jeab_demo_mode(): bool
{
    if (jeab_demo_env_on()) {
        return true;
    }
    $config = jeab_demo_config_array();

    return ($config['demo'] ?? null) === true;
}

/** @return array<string, mixed>|null */
function jeab_demo_person(): ?array
{
    if (!function_exists('jeab_people')) {
        return null;
    }
    foreach (jeab_people() as $row) {
        if (is_array($row) && trim((string) ($row['id'] ?? '')) !== '') {
            return $row;
        }
    }

    return null;
}

/** Stabil kaka-hash. Ingen riktig kod. Skrivs bara in i minnet när demon är på. */
function jeab_demo_auth_hash(): string
{
    return '{demo-local-hash}';
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function jeab_demo_auth_overlay(array $data): array
{
    if (!jeab_demo_mode()) {
        return $data;
    }
    $person = jeab_demo_person();
    $email = is_array($person) ? trim((string) ($person['email'] ?? '')) : '';
    $hash = jeab_demo_auth_hash();
    $entries = is_array($data['entries'] ?? null) ? $data['entries'] : [];
    $hashes = is_array($data['hashes'] ?? null) ? $data['hashes'] : [];
    array_unshift($entries, ['email' => $email, 'hash' => $hash]);
    array_unshift($hashes, $hash);
    $data['entries'] = $entries;
    $data['hashes'] = $hashes;
    if (trim((string) ($data['secret'] ?? '')) === '') {
        $data['secret'] = 'demo-local-cookie';
    }

    return $data;
}

function jeab_demo_log(string $channel, string $target = ''): void
{
    $dir = function_exists('jeab_data_dir') ? jeab_data_dir() : (dirname(__DIR__) . '/data/.private');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = gmdate('c') . "\t" . str_replace(["\t", "\r", "\n"], ' ', $channel)
        . "\t" . substr(hash('sha256', $target), 0, 12) . "\n";
    @file_put_contents($dir . '/demo-send.log', $line, FILE_APPEND | LOCK_EX);
}

/** True när anropet ska fejkas. Loggar då. */
function jeab_demo_outbound(string $channel, string $target = ''): bool
{
    if (!jeab_demo_mode()) {
        return false;
    }
    jeab_demo_log($channel, $target);

    return true;
}

/** @return array<string, mixed>|null */
function jeab_demo_sent(string $channel, string $target = ''): ?array
{
    if (!jeab_demo_outbound($channel, $target)) {
        return null;
    }
    if ($channel === 'sms') {
        return ['ok' => true, 'error' => null, 'demo' => true, 'id' => 'demo'];
    }

    return ['ok' => true, 'path' => 'demo', 'error' => null, 'id' => 'demo', 'demo' => true];
}

function jeab_demo_bind_session(): void
{
    if (!jeab_demo_mode() || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $person = jeab_demo_person();
    if (!is_array($person)) {
        return;
    }
    $id = trim((string) ($person['id'] ?? ''));
    if ($id !== '') {
        $_SESSION['jeab_code_person'] = $id;
        $_SESSION['jeab_user'] = $id;
        $_SESSION['jeab_user_via'] = 'identity';
        unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);
    }
    $company = function_exists('jeab_normalize_company') ? jeab_normalize_company($person['company'] ?? null) : null;
    if (is_string($company) && $company !== '' && trim((string) ($_SESSION['jeab_company'] ?? '')) === '') {
        $_SESSION['jeab_company'] = $company;
    }
}

/** Inloggad demoperson. Den är admin och ser båda kontoren. */
function jeab_demo_session_is_demo_person(): bool
{
    if (!jeab_demo_mode() || session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $person = jeab_demo_person();
    $id = is_array($person) ? trim((string) ($person['id'] ?? '')) : '';
    $sessionId = trim((string) ($_SESSION['jeab_code_person'] ?? ''));

    return $id !== '' && strcasecmp($sessionId, $id) === 0;
}

/** Flowstack-märket (inline SVG) som ersätter JE-rutan i demoläge. */
function jeab_demo_brand_logo_svg(): string
{
    return '<img src="/assets/img/flowstack-logo.png?v=2" width="32" height="32" alt="" style="display:block;width:32px;height:32px;object-fit:contain">';
}

/** Kampanjkoden för demoinloggning. Bara demo; kärnans login rör inte koden. */
function jeab_demo_campaign_code_ok(string $code): bool
{
    return strtolower(trim($code)) === 'cola';
}

/** Sidor och POST-åtgärder som inte finns i demon (manuell order, säck). Svarar 404. */
function jeab_demo_route_guard(string $page, string $action): void
{
    if (!jeab_demo_mode()) {
        return;
    }
    if (in_array($page, ['rot', 'manuellorder', 'manuell-order', 'sack'], true)
        || preg_match('/^(inkop\.|sack\.)/', $action) === 1) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Finns inte i demon.\n";
        exit;
    }
}

/** Tar bort länkar till delar som inte finns i demon. */
function jeab_demo_filter_links(array $links): array
{
    if (!jeab_demo_mode()) {
        return $links;
    }
    return array_values(array_filter($links, static function (array $item): bool {
        $href = (string) ($item[1] ?? '');
        return !str_contains($href, 'p=manuell-order') && !str_contains($href, 'p=sack') && !str_contains($href, 'solkampanj.php');
    }));
}

function jeab_demo_public_name(): string
{
    return 'Flowstack';
}

function jeab_demo_title(string $title): string
{
    if (!jeab_demo_mode()) {
        return $title;
    }
    if (str_contains($title, 'JEAB')) {
        return str_replace('JEAB', jeab_demo_public_name(), $title);
    }
    if (!str_contains($title, 'Flowstack')) {
        return $title . ' · ' . jeab_demo_public_name();
    }

    return $title;
}

function jeab_demo_feature_off(string $feature): bool
{
    return jeab_demo_mode() && in_array($feature, ['kalkyl', 'omtago'], true);
}

function jeab_demo_headers(): void
{
    if (!jeab_demo_mode() || PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || headers_sent()) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow');
}

function jeab_demo_banner_html(): string
{
    // Ingen banner/statusrad. Utskick spärras fortfarande i jeab_demo_sent/jeab_demo_outbound.
    return '';
}

function jeab_demo_forbid_feature(string $feature): void
{
    if (!jeab_demo_feature_off($feature)) {
        return;
    }
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        fwrite(STDERR, "demo: {$feature} av\n");
        exit(1);
    }
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
    }
    http_response_code(403);
    echo "Av i demon\n";
    exit;
}

function jeab_demo_forbid_cron(): void
{
    if (!jeab_demo_mode()) {
        return;
    }
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        fwrite(STDERR, "demo: cron av\n");
        exit(1);
    }
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
    }
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

function jeab_demo_forbid_hook(): void
{
    if (!jeab_demo_mode()) {
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
    }
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Fel token.'], JSON_UNESCAPED_UNICODE);
    exit;
}
