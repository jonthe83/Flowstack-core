<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/einstein_webhook.php';

const JEAB_BUG_MAX_BYTES = 5 * 1024 * 1024;
const JEAB_BUG_TYPES = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
];

function jeab_bug_secret_path(): string
{
    return jeab_einstein_webhook_secret_path();
}

function jeab_bug_reports_dir(): string
{
    $private = jeab_data_dir() . '/bug-reports';
    $legacy = jeab_public_data_dir() . '/bug-reports';
    if (!is_dir($private) && is_dir($legacy)) {
        $parent = dirname($private);
        if (!is_dir($parent)) {
            @mkdir($parent, 0775, true);
        }
        if (!@rename($legacy, $private) && is_dir($legacy) && !is_dir($private)) {
            $parentReady = is_dir($parent) && is_writable($parent);
            if (!$parentReady) {
                return $legacy;
            }
        }
    }
    return $private;
}

function jeab_bug_uploads_dir(): string
{
    return jeab_bug_reports_dir() . '/uploads';
}

function jeab_bug_deny_htaccess(string $dir): void
{
    $path = $dir . '/.htaccess';
    if (is_file($path)) {
        return;
    }
    @file_put_contents($path, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
}

function jeab_bug_ensure_dirs(): void
{
    foreach ([jeab_bug_reports_dir(), jeab_bug_uploads_dir()] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Kunde inte spara rapporten.');
        }
        jeab_bug_deny_htaccess($dir);
    }
}

function jeab_bug_new_id(): string
{
    return gmdate('Ymd') . '-' . bin2hex(random_bytes(8));
}

function jeab_bug_id_ok(string $id): bool
{
    return (bool) preg_match('/^[0-9]{8}-[a-f0-9]{16}$/', $id);
}

function jeab_bug_report_path(string $id): string
{
    return jeab_bug_reports_dir() . '/' . $id . '.json';
}

function jeab_bug_absolute_url(string $path = ''): string
{
    $scheme = jeab_is_https() ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($host === '') {
        $host = 'localhost';
    }
    return $scheme . '://' . $host . jeab_url($path);
}

function jeab_bug_app_url(): string
{
    return jeab_bug_absolute_url('');
}

function jeab_bug_screenshot_url(string $id): string
{
    return jeab_bug_absolute_url('index.php?p=bugg&file=' . rawurlencode($id));
}

function jeab_bug_reporter(): string
{
    $email = function_exists('jeab_auth_user_email') ? jeab_auth_user_email() : null;
    if ($email) {
        $person = function_exists('jeab_login_identity') ? (jeab_login_identity(false)['person'] ?? null) : null;
        $name = is_array($person) ? trim((string) ($person['name'] ?? '')) : '';
        return $name !== '' ? $name . ' <' . $email . '>' : $email;
    }
    return '';
}

function jeab_bug_normalize_page_url(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (strlen($raw) > 2000) {
        throw new InvalidArgumentException('Adressen är för lång.');
    }
    if (preg_match('/^(javascript|data|vbscript):/i', $raw)) {
        throw new InvalidArgumentException('Ogiltig adress.');
    }
    return $raw;
}

function jeab_bug_normalize_description(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        throw new InvalidArgumentException('Beskriv felet.');
    }
    if (function_exists('mb_strlen') ? mb_strlen($raw, 'UTF-8') > 20000 : strlen($raw) > 20000) {
        throw new InvalidArgumentException('Beskrivningen är för lång.');
    }
    return $raw;
}

function jeab_bug_secret(): ?array
{
    return jeab_einstein_webhook_secret();
}

function jeab_bug_webhook_host_blocked(string $url): bool
{
    return jeab_einstein_webhook_host_blocked($url);
}

function jeab_bug_upload_ext(array $file): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE || !isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
        return null;
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('Skärmdumpen är för stor (max 5 MB).');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Kunde inte läsa skärmdumpen.');
    }
    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
        throw new InvalidArgumentException('Kunde inte läsa skärmdumpen.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > JEAB_BUG_MAX_BYTES) {
        throw new InvalidArgumentException('Skärmdumpen är för stor (max 5 MB).');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $ext = JEAB_BUG_TYPES[$mime] ?? null;
    if ($ext === null) {
        throw new InvalidArgumentException('Skärmdump ska vara png, jpg eller webp.');
    }
    $info = @getimagesize($tmp);
    if (!is_array($info)) {
        throw new InvalidArgumentException('Skärmdump ska vara png, jpg eller webp.');
    }
    return $ext;
}

function jeab_bug_write_json(string $path, array $row): void
{
    $json = json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Kunde inte spara rapporten.');
    }
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte spara rapporten.');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Kunde inte spara rapporten.');
    }
}

function jeab_bug_read(string $id): ?array
{
    if (!jeab_bug_id_ok($id)) {
        return null;
    }
    $path = jeab_bug_report_path($id);
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function jeab_bug_payload(array $row): array
{
    $id = (string) ($row['id'] ?? '');
    $hasShot = trim((string) ($row['screenshot'] ?? '')) !== '';
    return [
        'source' => 'jeab',
        'type' => 'bug_report',
        'id' => $id,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'reporter' => (string) ($row['reporter'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'page_url' => $row['page_url'] ?? null,
        'screenshot_url' => $hasShot ? jeab_bug_screenshot_url($id) : null,
        'app_url' => jeab_bug_app_url(),
    ];
}

function jeab_bug_post_webhook(array $secret, array $payload): bool
{
    return jeab_einstein_webhook_post($secret, $payload);
}

function jeab_bug_submit(array $post, array $files): string
{
    $description = jeab_bug_normalize_description((string) ($post['description'] ?? ''));
    $pageUrl = jeab_bug_normalize_page_url((string) ($post['page_url'] ?? ''));
    $file = is_array($files['screenshot'] ?? null) ? $files['screenshot'] : [];
    $ext = jeab_bug_upload_ext($file);

    jeab_bug_ensure_dirs();
    $id = jeab_bug_new_id();
    $created = jeab_iso(jeab_now());
    $screenshotName = null;
    if ($ext !== null) {
        $screenshotName = $id . '.' . $ext;
        $dest = jeab_bug_uploads_dir() . '/' . $screenshotName;
        $tmp = (string) ($file['tmp_name'] ?? '');
        $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
        if (!$moved || !is_file($dest)) {
            throw new RuntimeException('Kunde inte spara skärmdumpen.');
        }
        @chmod($dest, 0600);
    }

    $row = [
        'id' => $id,
        'created_at' => $created,
        'reporter' => jeab_bug_reporter(),
        'description' => $description,
        'page_url' => $pageUrl,
        'screenshot' => $screenshotName,
        'webhook' => 'missing',
    ];
    jeab_bug_write_json(jeab_bug_report_path($id), $row);

    $secret = jeab_bug_secret();
    if ($secret === null) {
        return 'webhook ej konfigurerad';
    }
    $sent = jeab_bug_post_webhook($secret, jeab_bug_payload($row));
    $row['webhook'] = $sent ? 'sent' : 'failed';
    jeab_bug_write_json(jeab_bug_report_path($id), $row);
    if ($sent && function_exists('jeab_fellogg_open_ask_append')) {
        jeab_fellogg_open_ask_append([
            'bug_id' => $id,
            'kind' => 'fix',
        ]);
    }
    return $sent ? 'Skickad.' : 'Sparad.';
}

function jeab_bug_send_file(string $id): void
{
    $row = jeab_bug_read($id);
    $name = is_array($row) ? trim((string) ($row['screenshot'] ?? '')) : '';
    if ($name === '' || !preg_match('/^[0-9]{8}-[a-f0-9]{16}\.(png|jpg|webp)$/', $name)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Hittades inte.';
        exit;
    }
    $path = jeab_bug_uploads_dir() . '/' . $name;
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Hittades inte.';
        exit;
    }
    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}
