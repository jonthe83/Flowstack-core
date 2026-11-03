<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/notes_store.php';
const JEAB_NOTES_MAX_BYTES = 8 * 1024 * 1024;
const JEAB_NOTES_MAX_PHOTOS = 20;
const JEAB_NOTES_TYPES = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
];

function jeab_notes_is_public_store(string $path): bool
{
    $norm = str_replace('\\', '/', $path);
    $public = rtrim(str_replace('\\', '/', jeab_public_data_dir()), '/');
    $private = rtrim(str_replace('\\', '/', jeab_data_dir()), '/');
    $inPublic = $norm === $public || str_starts_with($norm, $public . '/');
    $inPrivate = $norm === $private || str_starts_with($norm, $private . '/');
    return $inPublic && !$inPrivate;
}

function jeab_notes_path(): string
{
    $private = jeab_data_dir() . '/notes.json';
    $landed = jeab_data_file('notes.json');
    if (jeab_notes_is_public_store($landed)) {
        return $private;
    }
    return $landed;
}

function jeab_notes_photos_dir(): string
{
    $private = jeab_data_dir() . '/notes-photos';
    $legacy = jeab_public_data_dir() . '/notes-photos';
    $legacyNorm = rtrim(str_replace('\\', '/', $legacy), '/');
    $privateNorm = rtrim(str_replace('\\', '/', $private), '/');
    if ($legacyNorm !== $privateNorm && is_dir($legacy)) {
        jeab_adopt_private_tree($legacy, $private);
    }
    return $private;
}

function jeab_notes_sections(): array
{
    return [
        'faltnoteringar' => 'Fältnoteringar',
    ];
}

function jeab_notes_section_label(string $key): string
{
    return jeab_notes_sections()[$key] ?? 'Fältnoteringar';
}

function jeab_notes_ensure(): bool
{
    $photos = jeab_notes_photos_dir();
    if (!is_dir($photos)) {
        @mkdir($photos, 0775, true);
    }
    if (jeab_notes_store_ready()) {
        return true;
    }
    $path = jeab_notes_path();
    if (is_readable($path)) {
        return true;
    }
    $legacy = jeab_public_data_dir() . '/notes.json';
    return is_readable($legacy);
}

/**
 * @template T
 * @param callable(array{next_id:int,notes:list<array<string,mixed>>}):T $fn
 * @return T
 */
function jeab_notes_mutate(callable $fn): mixed
{
    if (!jeab_notes_ensure()) {
        throw new RuntimeException('Kunde inte spara anteckningen.');
    }
    return jeab_notes_store_mutate($fn);
}

function jeab_notes_load(): array
{
    jeab_notes_ensure();
    return jeab_notes_store_load();
}

function jeab_notes_normalize_section(string $raw): string
{
    $raw = trim($raw);
    return array_key_exists($raw, jeab_notes_sections()) ? $raw : 'faltnoteringar';
}

function jeab_notes_parse_local(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw, $tz);
        if ($dt instanceof DateTimeImmutable) {
            if ($fmt === 'Y-m-d') {
                $dt = $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 0);
            }
            return $dt->format('Y-m-d H:i:s');
        }
    }
    try {
        return (new DateTimeImmutable($raw, $tz))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function jeab_notes_normalize(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $photos = [];
    foreach (is_array($row['photos'] ?? null) ? $row['photos'] : [] as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $pid = trim((string) ($photo['id'] ?? ''));
        if ($pid === '' || !preg_match('/^[a-f0-9]{16}$/', $pid)) {
            continue;
        }
        $photos[] = [
            'id' => $pid,
            'name' => trim((string) ($photo['name'] ?? 'bild.jpg')),
            'mime' => trim((string) ($photo['mime'] ?? 'image/jpeg')),
            'size' => (int) ($photo['size'] ?? 0),
        ];
    }
    $out = [
        'id' => $id,
        'title' => trim((string) ($row['title'] ?? '')),
        'body' => (string) ($row['body'] ?? ''),
        'section' => jeab_notes_normalize_section((string) ($row['section'] ?? 'faltnoteringar')),
        'event_at' => jeab_notes_parse_local($row['event_at'] ?? null) ?? jeab_now(),
        'event_end' => jeab_notes_parse_local($row['event_end'] ?? null),
        'created_at' => (string) ($row['created_at'] ?? jeab_now()),
        'updated_at' => (string) ($row['updated_at'] ?? jeab_now()),
        'photos' => $photos,
    ];
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    if ($contactId !== '') {
        $out['contact_id'] = $contactId;
    }
    return $out;
}

function jeab_notes_sv_date(string $local): string
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $local, $tz) ?: new DateTimeImmutable($local, $tz);
    $months = [1 => 'januari', 2 => 'februari', 3 => 'mars', 4 => 'april', 5 => 'maj', 6 => 'juni', 7 => 'juli', 8 => 'augusti', 9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december'];
    return (int) $dt->format('j') . ' ' . $months[(int) $dt->format('n')] . ' ' . $dt->format('Y');
}

function jeab_notes_sv_time(string $local): string
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $local, $tz) ?: new DateTimeImmutable($local, $tz);
    return $dt->format('H:i');
}

function jeab_notes_event_label(array $row): string
{
    $start = (string) ($row['event_at'] ?? '');
    if ($start === '') {
        return '';
    }
    $label = jeab_notes_sv_date($start);
    $from = jeab_notes_sv_time($start);
    $end = trim((string) ($row['event_end'] ?? ''));
    if ($end !== '') {
        $label .= ' · ' . $from . '–' . jeab_notes_sv_time($end);
    } elseif ($from !== '00:00') {
        $label .= ' · ' . $from;
    }
    return $label;
}

function jeab_notes_public(array $row): array
{
    $norm = jeab_notes_normalize($row);
    $photos = [];
    foreach ($norm['photos'] as $photo) {
        $photos[] = [
            'id' => $photo['id'],
            'name' => $photo['name'],
            'mime' => $photo['mime'],
            'size' => $photo['size'],
        ];
    }
    return [
        'id' => $norm['id'],
        'title' => $norm['title'],
        'body' => $norm['body'],
        'section' => $norm['section'],
        'section_label' => jeab_notes_section_label($norm['section']),
        'event_at' => $norm['event_at'],
        'event_end' => $norm['event_end'],
        'event_date' => substr($norm['event_at'], 0, 10),
        'event_start' => substr($norm['event_at'], 11, 5),
        'event_end_time' => $norm['event_end'] !== null ? substr($norm['event_end'], 11, 5) : '',
        'event_label' => jeab_notes_event_label($norm),
        'list_date' => jeab_notes_sv_date($norm['event_at']),
        'created_at' => $norm['created_at'],
        'updated_at' => $norm['updated_at'],
        'updated_label' => 'Senast sparad: ' . jeab_notes_sv_date($norm['updated_at']) . ', ' . jeab_notes_sv_time($norm['updated_at']),
        'photos' => $photos,
    ];
}

function jeab_notes_list(string $q = '', string $section = ''): array
{
    $needle = function_exists('mb_strtolower') ? mb_strtolower(trim($q), 'UTF-8') : strtolower(trim($q));
    $section = trim($section);
    $out = [];
    foreach (jeab_notes_load()['notes'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $norm = jeab_notes_normalize($row);
        if ($section !== '' && $section !== 'all' && $norm['section'] !== $section) {
            continue;
        }
        if ($needle !== '') {
            $hay = ($norm['title'] . ' ' . $norm['body']);
            $hay = function_exists('mb_strtolower') ? mb_strtolower($hay, 'UTF-8') : strtolower($hay);
            if (!str_contains($hay, $needle)) {
                continue;
            }
        }
        $out[] = jeab_notes_public($norm);
    }
    usort($out, static function (array $a, array $b): int {
        $cmp = strcmp((string) $b['event_at'], (string) $a['event_at']);
        return $cmp !== 0 ? $cmp : ((int) $b['id'] <=> (int) $a['id']);
    });
    return $out;
}

function jeab_notes_get(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    foreach (jeab_notes_load()['notes'] as $row) {
        if (is_array($row) && (int) ($row['id'] ?? 0) === $id) {
            return jeab_notes_public($row);
        }
    }
    return null;
}

function jeab_notes_create(array $input = []): array
{
    $now = jeab_now();
    return jeab_notes_mutate(static function (array &$data) use ($input, $now): array {
        $id = (int) ($data['next_id'] ?? 1);
        $data['next_id'] = $id + 1;
        $event = jeab_notes_parse_local($input['event_at'] ?? null) ?? $now;
        $row = jeab_notes_normalize([
            'id' => $id,
            'title' => trim((string) ($input['title'] ?? '')),
            'body' => (string) ($input['body'] ?? ''),
            'section' => $input['section'] ?? 'faltnoteringar',
            'event_at' => $event,
            'event_end' => $input['event_end'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
            'photos' => [],
        ]);
        $data['notes'][] = $row;
        return jeab_notes_public($row);
    });
}

function jeab_notes_update(int $id, array $input): array
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Anteckningen saknas.');
    }
    return jeab_notes_mutate(static function (array &$data) use ($id, $input): array {
        foreach ($data['notes'] as $i => $row) {
            if (!is_array($row) || (int) ($row['id'] ?? 0) !== $id) {
                continue;
            }
            $cur = jeab_notes_normalize($row);
            if (array_key_exists('title', $input)) {
                $cur['title'] = trim((string) $input['title']);
            }
            if (array_key_exists('body', $input)) {
                $body = (string) $input['body'];
                if (function_exists('mb_strlen') ? mb_strlen($body, 'UTF-8') > 50000 : strlen($body) > 50000) {
                    throw new InvalidArgumentException('Anteckningen är för lång.');
                }
                $cur['body'] = $body;
            }
            if (array_key_exists('section', $input)) {
                $cur['section'] = jeab_notes_normalize_section((string) $input['section']);
            }
            if (array_key_exists('event_at', $input)) {
                $parsed = jeab_notes_parse_local($input['event_at'] ?? null);
                if ($parsed !== null) {
                    $cur['event_at'] = $parsed;
                }
            }
            if (array_key_exists('event_end', $input)) {
                $cur['event_end'] = jeab_notes_parse_local($input['event_end'] ?? null);
            }
            $cur['updated_at'] = jeab_now();
            $data['notes'][$i] = $cur;
            return jeab_notes_public($cur);
        }
        throw new InvalidArgumentException('Anteckningen saknas.');
    });
}

function jeab_notes_delete(int $id): void
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Anteckningen saknas.');
    }
    if (!jeab_notes_ensure()) {
        throw new RuntimeException('Kunde inte spara anteckningen.');
    }
    $pdo = jeab_notes_store_pdo();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Kunde inte spara anteckningen.');
    }
    jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($id): void {
        if (!jeab_notes_store_is_imported($pdo)) {
            $copied = jeab_notes_store_copy_json($pdo);
            $state = jeab_notes_store_state($pdo);
            jeab_notes_store_put_state($pdo, max($copied['next_id'], $state['next_id']), 1);
        }
        $stmt = $pdo->prepare('SELECT id FROM notes WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Anteckningen saknas.');
        }
        $pdo->prepare('DELETE FROM notes WHERE id = ?')->execute([$id]);
    });
    foreach ([jeab_notes_photos_dir(), jeab_public_data_dir() . '/notes-photos'] as $base) {
        $dir = $base . '/' . $id;
        if (!is_dir($dir)) {
            continue;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}

function jeab_notes_photo_ext(array $file): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE || !isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
        throw new InvalidArgumentException('Ingen bild vald.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('Bilden är för stor (max 8 MB).');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Kunde inte läsa bilden.');
    }
    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
        throw new InvalidArgumentException('Kunde inte läsa bilden.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > JEAB_NOTES_MAX_BYTES) {
        throw new InvalidArgumentException('Bilden är för stor (max 8 MB).');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $ext = JEAB_NOTES_TYPES[$mime] ?? null;
    if ($ext === null) {
        throw new InvalidArgumentException('Bilden ska vara png, jpg eller webp.');
    }
    $info = @getimagesize($tmp);
    if (!is_array($info)) {
        throw new InvalidArgumentException('Bilden ska vara png, jpg eller webp.');
    }
    return $ext;
}

function jeab_notes_add_photo(int $id, array $file): array
{
    if ($id <= 0 || jeab_notes_get($id) === null) {
        throw new InvalidArgumentException('Anteckningen saknas.');
    }
    $ext = jeab_notes_photo_ext($file);
    $pid = bin2hex(random_bytes(8));
    $dir = jeab_notes_photos_dir() . '/' . $id;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte spara bilden.');
    }
    $dest = $dir . '/' . $pid . '.' . $ext;
    $tmp = (string) $file['tmp_name'];
    $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
    if (!$moved && is_file($tmp)) {
        $moved = @copy($tmp, $dest);
    }
    if (!$moved || !is_file($dest)) {
        throw new RuntimeException('Kunde inte spara bilden.');
    }
    $name = trim((string) ($file['name'] ?? ''));
    if ($name === '') {
        $name = 'bild.' . $ext;
    }
    $name = preg_replace('/[^\w.\-]+/u', '_', $name) ?: ('bild.' . $ext);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $photo = [
        'id' => $pid,
        'name' => $name,
        'mime' => (string) ($finfo->file($dest) ?: 'image/jpeg'),
        'size' => (int) filesize($dest),
    ];
    return jeab_notes_mutate(static function (array &$data) use ($id, $photo): array {
        foreach ($data['notes'] as $i => $row) {
            if (!is_array($row) || (int) ($row['id'] ?? 0) !== $id) {
                continue;
            }
            $cur = jeab_notes_normalize($row);
            if (count($cur['photos']) >= JEAB_NOTES_MAX_PHOTOS) {
                throw new InvalidArgumentException('Högst 20 bilder per anteckning.');
            }
            $cur['photos'][] = $photo;
            $cur['updated_at'] = jeab_now();
            $data['notes'][$i] = $cur;
            return jeab_notes_public($cur);
        }
        throw new InvalidArgumentException('Anteckningen saknas.');
    });
}

function jeab_notes_delete_photo(int $id, string $photoId): array
{
    if ($id <= 0 || !preg_match('/^[a-f0-9]{16}$/', $photoId)) {
        throw new InvalidArgumentException('Bilden saknas.');
    }
    $row = jeab_notes_mutate(static function (array &$data) use ($id, $photoId): array {
        foreach ($data['notes'] as $i => $note) {
            if (!is_array($note) || (int) ($note['id'] ?? 0) !== $id) {
                continue;
            }
            $cur = jeab_notes_normalize($note);
            $kept = [];
            $found = false;
            foreach ($cur['photos'] as $photo) {
                if ($photo['id'] === $photoId) {
                    $found = true;
                    continue;
                }
                $kept[] = $photo;
            }
            if (!$found) {
                throw new InvalidArgumentException('Bilden saknas.');
            }
            $cur['photos'] = $kept;
            $cur['updated_at'] = jeab_now();
            $data['notes'][$i] = $cur;
            return jeab_notes_public($cur);
        }
        throw new InvalidArgumentException('Anteckningen saknas.');
    });
    foreach ([jeab_notes_photos_dir(), jeab_public_data_dir() . '/notes-photos'] as $base) {
        foreach (['png', 'jpg', 'webp'] as $ext) {
            $path = $base . '/' . $id . '/' . $photoId . '.' . $ext;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
    return $row;
}

function jeab_notes_send_photo(int $id, string $photoId): void
{
    $row = jeab_notes_get($id);
    if ($row === null || !preg_match('/^[a-f0-9]{16}$/', $photoId)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Hittades inte.';
        exit;
    }
    $found = null;
    foreach ($row['photos'] as $photo) {
        if (($photo['id'] ?? '') === $photoId) {
            $found = $photo;
            break;
        }
    }
    if ($found === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Hittades inte.';
        exit;
    }
    $path = null;
    $bases = [jeab_notes_photos_dir(), jeab_public_data_dir() . '/notes-photos'];
    foreach ($bases as $base) {
        foreach (['png', 'jpg', 'webp'] as $ext) {
            $try = $base . '/' . $id . '/' . $photoId . '.' . $ext;
            if (is_file($try)) {
                $path = $try;
                break 2;
            }
        }
    }
    if ($path === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Hittades inte.';
        exit;
    }
    header('Content-Type: ' . (string) ($found['mime'] ?? 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="' . basename((string) $found['name']) . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}
