<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/shield.php';
jeab_deny_if_direct(__FILE__);

function fullmakt_mark_viewed(string $token): bool
{
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token) ?? '';
    if ($token === '' || strlen($token) > 80) {
        return false;
    }
    if (function_exists('fullmakt_sql_active') && fullmakt_sql_active()) {
        return fullmakt_mark_viewed_sql($token);
    }
    if (!function_exists('fullmakt_pending_path')) {
        return false;
    }
    $path = fullmakt_pending_path($token);
    if (!is_file($path)) {
        return false;
    }
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        return false;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return false;
        }
        rewind($fh);
        $raw = stream_get_contents($fh);
        $doc = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($doc) || ($doc['status'] ?? '') === 'signed') {
            return false;
        }
        if (trim((string) ($doc['viewed_at'] ?? '')) !== '') {
            return true;
        }
        $doc['viewed_at'] = fullmakt_now()->format(DateTimeInterface::ATOM);
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        rewind($fh);
        $written = fwrite($fh, $json);
        if ($written === false || $written !== strlen($json) || !ftruncate($fh, strlen($json))) {
            return false;
        }
        fflush($fh);
        return true;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function fullmakt_mark_viewed_sql(string $token): bool
{
    if (!function_exists('fullmakt_sql_get') || !function_exists('fullmakt_sql_put')) {
        return false;
    }
    $doc = fullmakt_sql_get($token);
    if (!is_array($doc) || ($doc['status'] ?? '') === 'signed') {
        return false;
    }
    if (trim((string) ($doc['viewed_at'] ?? '')) !== '') {
        return true;
    }
    $doc['token'] = $token;
    $doc['viewed_at'] = fullmakt_now()->format(DateTimeInterface::ATOM);
    try {
        fullmakt_sql_put($doc);
    } catch (Throwable) {
        return false;
    }

    return true;
}
