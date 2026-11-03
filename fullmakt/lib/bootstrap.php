<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/shield.php';
jeab_deny_if_direct(__FILE__);

define('FULLMAKT_ROOT', dirname(__DIR__));

function fullmakt_data_dir(): string
{
    $env = getenv('FULLMAKT_DATA');
    if (is_string($env) && $env !== '') {
        return rtrim(str_replace('\\', '/', $env), '/');
    }
    $webRoot = dirname(FULLMAKT_ROOT);
    $outsideParent = dirname($webRoot) . '/privat';
    $outside = $outsideParent . '/fullmakt';
    if (is_dir($outside) || (is_dir($outsideParent) && is_writable($outsideParent))) {
        return str_replace('\\', '/', $outside);
    }
    return str_replace('\\', '/', $webRoot . '/data/.private/fullmakt');
}

define('FULLMAKT_DATA', fullmakt_data_dir());
define('FULLMAKT_PENDING', FULLMAKT_DATA . '/pending');
define('FULLMAKT_SIGNED', FULLMAKT_DATA . '/signed');
define('FULLMAKT_TMP', FULLMAKT_DATA . '/tmp');
define('FULLMAKT_ORIGINALS', FULLMAKT_DATA . '/originals');

const FULLMAKT_INSTALLER = [
    'foretag' => 'Demo Energi Järfälla',
    'orgnr' => '559999-0000',
    'namn' => 'Demo Säljare',
    'telefon' => '08-000 00 06',
    'adress' => 'Trehörningen 17',
    'epost' => 'kontakt@jarfalla.example.test',
    'postnummer' => '176 74',
    'ort' => 'Järfälla',
];

const FULLMAKT_TYPER = [
    'privatperson' => 'Privatperson',
    'enskild' => 'Enskild firma',
    'foretag' => 'Företag med organisationsnummer',
];

const FULLMAKT_DOC_TYPES = ['ellevio', 'overlay'];

/** Gemensam rubrik överst på fullmakten (JEAB och MEAB). */
const FULLMAKT_BRAND_KICKER = 'Demo Energi Järfälla & Demo Energi Malmö';

const FULLMAKT_COPY = [
    'ellevio' => [
        'title' => 'Fullmakt till Ellevio',
        'lead' => 'Du ger Demo Energi Järfälla rätt att anmäla din produktionsanläggning till Ellevio. Kontrollera uppgifterna och signera.',
        'button' => 'Signera fullmakt',
        'invite_subject' => 'Fullmakt till Ellevio — signera',
        'invite_body' => "Hej {namn}, för att vi ska kunna anmäla solcellerna till Ellevio behöver du signera fullmakten.\n\nDu måste fylla i anläggnings-id. Det står på elmätaren och på elfakturan.\nDen som signerar måste vara samma person som står på elfakturan.\nI dokumentet kan du välja vem som signerar.\nLänk: {url}\n",
        'tack_lead' => 'Dokumentet är signerat. Vi anmäler produktionsanläggningen till Ellevio.',
    ],
    'overlay' => [
        'title' => 'Signera dokument',
        'lead' => 'Kontrollera dokumentet och signera på den prickade linjen.',
        'button' => 'Signera',
        'invite_subject' => 'Dokument att signera',
        'invite_body' => "Hej {namn}, kan du signera dokumentet? Länk: {url}\n",
        'tack_lead' => 'Dokumentet är signerat. Du kan stänga den här sidan.',
    ],
];

/** @return list<string> */
function fullmakt_legacy_dirs(): array
{
    $canonical = rtrim(str_replace('\\', '/', FULLMAKT_DATA), '/');
    $web = rtrim(str_replace('\\', '/', dirname(FULLMAKT_ROOT)), '/');
    $candidates = [
        rtrim(str_replace('\\', '/', FULLMAKT_ROOT . '/data'), '/'),
        $web . '/data/.private/fullmakt',
    ];
    $out = [];
    foreach ($candidates as $dir) {
        if ($dir === $canonical || in_array($dir, $out, true)) {
            continue;
        }
        $out[] = $dir;
    }
    return $out;
}

function fullmakt_tighten_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    @chmod($dir, 0700);
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_dir($path) && !is_link($path)) {
            fullmakt_tighten_tree($path);
            continue;
        }
        if (is_file($path)) {
            @chmod($path, $name === 'secret.php' ? 0600 : 0640);
        }
    }
}

function fullmakt_migrate_legacy(): bool
{
    if (!is_dir(FULLMAKT_DATA) && !@mkdir(FULLMAKT_DATA, 0700, true) && !is_dir(FULLMAKT_DATA)) {
        return false;
    }
    $moved = false;
    foreach (fullmakt_legacy_dirs() as $legacy) {
        if (!is_dir($legacy)) {
            continue;
        }
        $before = is_dir($legacy);
        if (jeab_adopt_private_tree($legacy, FULLMAKT_DATA)) {
            $moved = $moved || ($before && !is_dir($legacy));
        } else {
            $moved = true;
        }
    }
    return $moved;
}

function fullmakt_slot(string $relative): string
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    $canonical = FULLMAKT_DATA . '/' . $relative;
    if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
        return $canonical;
    }
    $dir = dirname($canonical);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_file($canonical)) {
        return $canonical;
    }
    foreach (fullmakt_legacy_dirs() as $root) {
        $legacy = $root . '/' . $relative;
        if (!is_file($legacy)) {
            continue;
        }
        if (is_dir($dir) && function_exists('jeab_data_try_move') && jeab_data_try_move($legacy, $canonical)) {
            @chmod($canonical, str_ends_with($relative, 'secret.php') ? 0600 : 0640);
            return $canonical;
        }
        return $legacy;
    }
    return $canonical;
}

function fullmakt_boot(): void
{
    $moved = fullmakt_migrate_legacy();
    foreach ([FULLMAKT_DATA, FULLMAKT_PENDING, FULLMAKT_SIGNED, FULLMAKT_TMP, FULLMAKT_ORIGINALS] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            continue;
        }
        @chmod($dir, 0700);
    }
    $secret = FULLMAKT_DATA . '/secret.php';
    if (!is_file($secret)) {
        $secret = fullmakt_slot('secret.php');
    }
    if (is_file($secret)) {
        @chmod($secret, 0600);
    }
    if ($moved && is_dir(FULLMAKT_DATA)) {
        fullmakt_tighten_tree(FULLMAKT_DATA);
    }
}

function fullmakt_key_from_file(string $file): ?string
{
    if (!is_readable($file)) {
        return null;
    }
    $FULLMAKT_KEY = null;
    require $file;
    return is_string($FULLMAKT_KEY) && $FULLMAKT_KEY !== '' ? $FULLMAKT_KEY : null;
}

function fullmakt_key(): ?string
{
    $env = getenv('FULLMAKT_KEY');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    $canonical = FULLMAKT_DATA . '/secret.php';
    $file = is_file($canonical) ? $canonical : fullmakt_slot('secret.php');
    $key = fullmakt_key_from_file($file);
    if ($key !== null) {
        return $key;
    }
    foreach (fullmakt_legacy_dirs() as $dir) {
        $key = fullmakt_key_from_file($dir . '/secret.php');
        if ($key !== null) {
            return $key;
        }
    }
    return null;
}

if (!function_exists('e')) {
    function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function fullmakt_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm'));
}

function fullmakt_client_ip(): string
{
    $direct = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($direct !== '' && filter_var($direct, FILTER_VALIDATE_IP)) {
        return $direct;
    }
    return '';
}

function fullmakt_web_base(): string
{
    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $root = realpath(FULLMAKT_ROOT);
    if ($doc && $root && strncmp($root, $doc, strlen($doc)) === 0) {
        $rel = str_replace('\\', '/', substr($root, strlen($doc)));
        return $rel === '' || $rel === '/' ? '' : $rel;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#^(.*?)/fullmakt(?:/|$)#', $script, $m)) {
        return $m[1] . '/fullmakt';
    }
    $dir = str_replace('\\', '/', dirname($script));
    if (substr($dir, -4) === '/api') {
        $dir = dirname($dir);
    }
    if ($dir === '/' || $dir === '.' || $dir === '\\') {
        return '';
    }
    return $dir;
}

function fullmakt_public_base(): string
{
    $env = getenv('FULLMAKT_PUBLIC_URL');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }
    // Länken mejlas. HTTP_HOST och X-Forwarded-Proto får inte styra den.
    return 'https://flowstack.example.test/fullmakt';
}

function fullmakt_sign_url(string $token): string
{
    return fullmakt_public_base() . '/s/' . $token;
}

function fullmakt_request_token(): string
{
    $raw = (string) ($_GET['token'] ?? $_GET['t'] ?? '');
    if ($raw === '' && preg_match('#/s/([A-Za-z0-9_-]+)#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $m)) {
        $raw = $m[1];
    }
    return preg_replace('/[^A-Za-z0-9_-]/', '', $raw) ?? '';
}

function fullmakt_doc_type(array $doc): string
{
    $type = (string) ($doc['type'] ?? 'ellevio');
    return in_array($type, FULLMAKT_DOC_TYPES, true) ? $type : 'ellevio';
}

function fullmakt_anlaggnings_id_required(array $doc): bool
{
    return fullmakt_doc_type($doc) !== 'overlay';
}

function fullmakt_anlaggnings_id_digits(string $raw): string
{
    return preg_replace('/\D+/', '', $raw) ?? '';
}

function fullmakt_anlaggnings_id_error(string $raw): string
{
    $digits = fullmakt_anlaggnings_id_digits($raw);
    if ($digits === '') {
        return 'Fyll i anläggnings-id innan du signerar. 18 siffror, står vid elmätaren och på elfakturan.';
    }
    if (strlen($digits) !== 18) {
        return 'Ange anläggnings-id med exakt 18 siffror. Inga bokstäver.';
    }
    return '';
}

/**
 * Ellevio-fullmakt får inte stämplas utan 18-siffrigt anläggnings-id.
 * Overlay-dokument (annan blankett) berörs inte.
 *
 * @return array samma dokument med normaliserat anlaggnings_id när det krävs
 */
function fullmakt_assert_ellevio_anlaggnings_id(array $doc): array
{
    if (!fullmakt_anlaggnings_id_required($doc)) {
        return $doc;
    }
    $digits = fullmakt_anlaggnings_id_digits((string) ($doc['anlaggnings_id'] ?? ''));
    $error = fullmakt_anlaggnings_id_error($digits);
    if ($error !== '') {
        throw new RuntimeException($error);
    }
    $doc['anlaggnings_id'] = $digits;
    return $doc;
}

/**
 * Värde att visa i fältet. Postat värde vinner (även tomt, så ett rensat fält
 * inte fylls tillbaka i smyg). Annars dokumentet, som kan vara förifyllt från ordern.
 */
function fullmakt_prefill_anlaggnings_id(array $doc, array $post): string
{
    if (array_key_exists('anlaggnings_id', $post)) {
        return fullmakt_anlaggnings_id_digits((string) $post['anlaggnings_id']);
    }
    return fullmakt_anlaggnings_id_digits((string) ($doc['anlaggnings_id'] ?? ''));
}

function fullmakt_copy(array $doc, string $key): string
{
    if ($key === 'kicker') {
        return FULLMAKT_BRAND_KICKER;
    }
    $override = $doc[$key] ?? null;
    if (is_string($override) && $override !== '') {
        return $override;
    }
    $type = fullmakt_doc_type($doc);
    if (isset(FULLMAKT_COPY[$type][$key])) {
        return FULLMAKT_COPY[$type][$key];
    }
    return FULLMAKT_COPY['ellevio'][$key] ?? '';
}

function fullmakt_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fullmakt_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    if (function_exists('jeab_csp_value')) {
        header('Content-Security-Policy: ' . jeab_csp_value('none'));
    }
}

function fullmakt_log(string $file, string $line): void
{
    @file_put_contents(
        FULLMAKT_TMP . '/' . $file,
        date('c') . ' ' . $line . "\n",
        FILE_APPEND | LOCK_EX
    );
}
