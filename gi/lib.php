<?php

require_once __DIR__ . '/config.php';

function app_boot(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    date_default_timezone_set(TIMEZONE);
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $sent = $_POST['csrf'] ?? '';
    $have = $_SESSION['csrf'] ?? '';
    return is_string($sent) && is_string($have) && $have !== '' && hash_equals($have, $sent);
}

function data_file(): string
{
    $legacy = __DIR__ . '/data/loans.json';
    $shield = dirname(__DIR__) . '/src/shield.php';
    if (is_file($shield)) {
        require_once $shield;
        $store = dirname(__DIR__) . '/src/gi_loans_store.php';
        if (is_file($store)) {
            require_once $store;
        }
        if (function_exists('jeab_gi_loans_file')) {
            return jeab_gi_loans_file($legacy);
        }
    }
    return $legacy;
}

function photos_dir(): string
{
    return __DIR__ . '/data/photos';
}

function empty_store(): array
{
    return ['loans' => []];
}

function decode_store(?string $raw): array
{
    if ($raw === null || $raw === '') {
        return empty_store();
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['loans']) || !is_array($data['loans'])) {
        return empty_store();
    }
    return $data;
}

function load_store(): array
{
    if (function_exists('jeab_gi_loans_load')) {
        return jeab_gi_loans_load();
    }
    $path = data_file();
    if (!is_file($path)) {
        return empty_store();
    }
    return decode_store(file_get_contents($path));
}

function mutate_store(callable $mutator)
{
    if (function_exists('jeab_gi_loans_use_sql') && jeab_gi_loans_use_sql()) {
        return jeab_gi_loans_mutate($mutator);
    }
    $path = data_file();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
        @chmod($dir, 0700);
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException('Kunde inte öppna datafilen.');
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('Kunde inte låsa datafilen.');
        }
        $raw = stream_get_contents($fp);
        $data = decode_store($raw === false ? '' : $raw);
        $result = $mutator($data);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Kunde inte spara.');
        }
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $json . "\n");
        fflush($fp);
        return $result;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
        @chmod($path, 0600);
    }
}

function is_active(array $loan): bool
{
    return ($loan['status'] ?? '') === 'active';
}

function active_loans(array $store): array
{
    return array_values(array_filter($store['loans'], 'is_active'));
}

function find_loan(array $store, string $id): ?array
{
    foreach ($store['loans'] as $loan) {
        if (($loan['id'] ?? '') === $id) {
            return $loan;
        }
    }
    return null;
}

function valid_id(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}$/', $id);
}

function new_id(): string
{
    return bin2hex(random_bytes(16));
}

function now_iso(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(TIMEZONE)))->format(DateTimeInterface::ATOM);
}

function format_when(string $iso): string
{
    try {
        $dt = new DateTimeImmutable($iso);
        $dt = $dt->setTimezone(new DateTimeZone(TIMEZONE));
    } catch (Exception $e) {
        return $iso;
    }
    $months = [
        1 => 'jan', 2 => 'feb', 3 => 'mar', 4 => 'apr',
        5 => 'maj', 6 => 'jun', 7 => 'jul', 8 => 'aug',
        9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec',
    ];
    $m = $months[(int) $dt->format('n')] ?? $dt->format('m');
    return $dt->format('j') . ' ' . $m . ' ' . $dt->format('Y') . ' · ' . $dt->format('H:i');
}

function photo_url(string $id): string
{
    return 'photo.php?id=' . rawurlencode($id);
}

function save_photo_upload(array $file, string $id): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fotot saknas eller gick inte att ladda upp.');
    }
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('Fotot är för stort (max 10 MB).');
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Ogiltig filuppladdning.');
    }
    $info = @getimagesize($tmp);
    if ($info === false) {
        throw new RuntimeException('Kunde inte läsa fotot. Använd JPEG, PNG eller WebP.');
    }
    $raw = file_get_contents($tmp);
    if ($raw === false) {
        throw new RuntimeException('Kunde inte läsa fotot.');
    }
    $im = @imagecreatefromstring($raw);
    if ($im === false) {
        throw new RuntimeException('Kunde inte läsa fotot. Använd JPEG, PNG eller WebP.');
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $max = 1200;
    if ($w > $max || $h > $max) {
        $scale = $max / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $resized = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($resized, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $resized;
    }
    $dir = photos_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
        @chmod($dir, 0777);
    }
    $name = $id . '.jpg';
    $dest = $dir . '/' . $name;
    imagejpeg($im, $dest, 85);
    imagedestroy($im);
    @chmod($dest, 0666);
    return $name;
}

function delete_photo_file(string $id): void
{
    $path = photos_dir() . '/' . $id . '.jpg';
    if (is_file($path)) {
        @unlink($path);
    }
}

function normalize_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    return $name;
}

function normalize_phone(string $phone): string
{
    $phone = trim(preg_replace('/[^\d+\s-]/u', '', $phone) ?? $phone);
    $phone = trim(preg_replace('/\s+/u', ' ', $phone) ?? $phone);
    return $phone;
}

function phone_digits(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_take(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($f) ? $f : null;
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function html_start(string $title, array $opts = []): void
{
    $bodyClass = $opts['body'] ?? '';
    $theme = $opts['theme'] ?? '#111111';
    echo '<!doctype html><html lang="sv"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<meta name="theme-color" content="' . h($theme) . '">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<title>' . h($title) . '</title>';
    $css = $opts['css'] ?? 'style.css';
    if (strpos($css, '?') === false && !preg_match('#^https?://#', $css)) {
        $cssFile = __DIR__ . '/' . ltrim($css, '/');
        $css .= '?v=' . (is_file($cssFile) ? (string) filemtime($cssFile) : '0');
    }
    echo '<link rel="stylesheet" href="' . h($css) . '">';
    echo '</head><body class="' . h($bodyClass) . '">';
}

function html_end(): void
{
    echo '</body></html>';
}

function render_flash(?array $flash): void
{
    if (!$flash) {
        return;
    }
    $type = $flash['type'] === 'ok' ? 'ok' : 'err';
    echo '<p class="flash flash-' . $type . '" role="status">' . h($flash['msg'] ?? '') . '</p>';
}

function render_person_row(array $loan, string $href, bool $showPhone = false): void
{
    $name = (string) ($loan['name'] ?? '');
    $id = (string) ($loan['id'] ?? '');
    echo '<a class="person" href="' . h($href) . '">';
    echo '<span class="person-text">';
    echo '<span class="person-name">' . h($name) . '</span>';
    if ($showPhone) {
        $phone = (string) ($loan['phone'] ?? '');
        echo '<span class="person-meta">' . h($phone) . '</span>';
    }
    echo '</span>';
    echo '<span class="chev" aria-hidden="true">›</span>';
    echo '</a>';
}
