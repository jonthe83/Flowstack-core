<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * fullmakt pending/signed JSON → fullmakt_docs. PDF och bilder ligger kvar på disk.
 * En fil per token importeras. En lista docs slås ihop med filerna.
 */

function jeab_migrate_fullmakt_module(): array
{
    return jeab_migrate_declare(
        'fullmakt',
        'fullmakt_docs',
        'fullmakt/data',
        'token',
        ['token', 'status', 'created_at', 'payload'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS fullmakt_docs (
    token VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL,
    created_at DATETIME DEFAULT NULL,
    payload LONGTEXT NOT NULL,
    PRIMARY KEY (token),
    KEY fullmakt_docs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS fullmakt_docs (
    token TEXT PRIMARY KEY,
    status TEXT NOT NULL,
    created_at TEXT,
    payload TEXT NOT NULL
)
SQL
    );
}

function jeab_migrate_fullmakt_primary_dir(): string
{
    $env = getenv('FULLMAKT_DATA');
    if (is_string($env) && $env !== '') {
        return rtrim(str_replace('\\', '/', $env), '/');
    }
    $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__, 2);
    $privat = dirname($root) . '/privat/fullmakt';
    if (is_dir($privat)) {
        return str_replace('\\', '/', $privat);
    }
    $inside = $root . '/data/.private/fullmakt';
    if (is_dir($inside)) {
        return str_replace('\\', '/', $inside);
    }
    $legacy = $root . '/fullmakt/data';
    if (is_dir($legacy)) {
        return str_replace('\\', '/', $legacy);
    }

    return '';
}

/** @return list<string> */
function jeab_migrate_fullmakt_file_list(): array
{
    $dir = jeab_migrate_fullmakt_primary_dir();
    if ($dir === '') {
        return [];
    }
    $out = [];
    foreach (['pending', 'signed'] as $slot) {
        $files = glob($dir . '/' . $slot . '/*.json') ?: [];
        foreach ($files as $path) {
            if (is_file($path)) {
                $out[] = $path;
            }
        }
    }

    return $out;
}

/** @return list<array<string, mixed>> */
function jeab_migrate_fullmakt_collect_files(): array
{
    $docs = [];
    foreach (jeab_migrate_fullmakt_file_list() as $path) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            continue;
        }
        $token = basename($path, '.json');
        if (!isset($decoded['token']) || (string) $decoded['token'] === '') {
            $decoded['token'] = $token;
        }
        if (!isset($decoded['status']) || (string) $decoded['status'] === '') {
            $decoded['status'] = str_contains(str_replace('\\', '/', $path), '/signed/') ? 'signed' : 'pending';
        }
        $docs[] = $decoded;
    }

    return $docs;
}

/** @param array<string, mixed> $source */
function jeab_migrate_fullmakt_rows(array $source): array
{
    $rows = [];
    $seen = [];
    $docs = array_merge(jeab_migrate_list($source, 'docs'), jeab_migrate_fullmakt_collect_files());
    foreach ($docs as $doc) {
        $clean = jeab_migrate_redact($doc);
        if (!is_array($clean)) {
            continue;
        }
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($clean['token'] ?? '')) ?? '';
        if ($token === '' || isset($seen[$token])) {
            continue;
        }
        $seen[$token] = true;
        $clean['token'] = $token;
        $status = jeab_migrate_text((string) ($clean['status'] ?? '')) ?? 'pending';
        $rows[] = [
            'token' => $token,
            'status' => $status,
            'created_at' => jeab_migrate_datetime($clean['created_at'] ?? null),
            'payload' => jeab_migrate_payload($clean),
        ];
    }
    return jeab_migrate_project($rows, 'token', jeab_migrate_fullmakt_module()['columns']);
}

function jeab_migrate_fullmakt_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_fullmakt_rows($source));
}

/**
 * Räknare till verify: dokument som bara finns som JSON.
 * Läser inte om store.php redan är laddad. Startar ingen filflytt.
 */
function jeab_migrate_fullmakt_json_only(): int
{
    try {
        if (!function_exists('fullmakt_json_only_count')) {
            $root = dirname(__DIR__, 2);
            $boot = $root . '/fullmakt/lib/bootstrap.php';
            $store = $root . '/fullmakt/lib/store.php';
            if (!is_file($boot) || !is_file($store)) {
                return 0;
            }
            require_once $boot;
            require_once $store;
        }

        return function_exists('fullmakt_json_only_count') ? fullmakt_json_only_count() : 0;
    } catch (Throwable) {
        return 0;
    }
}
