<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * Affärsdokument → json_docs med nyckel biz_<namn>.
 * Live MySQL är redan källan och hoppas över.
 */

function jeab_migrate_biz_docs_module(): array
{
    $module = jeab_migrate_module(
        'biz_docs',
        'json_docs',
        'data/code-people.json',
        'doc_key',
        ['doc_key', 'payload', 'updated_at'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS json_docs (
    doc_key VARCHAR(64) NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (doc_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS json_docs (
    doc_key TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL
    );
    $module['json'] = [
        'data/code-people.json',
        'data/reminder-ort-cache.json',
        'data/reminders-prune.json',
        'data/webshop-catchup.json',
    ];

    return $module;
}

/** @return array<string, mixed> */
function jeab_migrate_biz_docs_source(): array
{
    if (!function_exists('jeab_biz_doc_json_read')) {
        require_once __DIR__ . '/../biz_doc_store.php';
    }
    $names = function_exists('jeab_biz_doc_names')
        ? jeab_biz_doc_names()
        : ['code_people', 'reminder_ort_cache', 'reminders_prune', 'webshop_catchup'];
    $out = [];
    foreach ($names as $name) {
        $out[$name] = jeab_biz_doc_json_read($name);
    }

    return $out;
}

function jeab_migrate_biz_docs_authoritative_sql(): bool
{
    return function_exists('jeab_biz_doc_mysql_live') && jeab_biz_doc_mysql_live();
}

/** @param array<string, mixed> $source */
function jeab_migrate_biz_docs_rows(array $source): array
{
    $names = function_exists('jeab_biz_doc_names') ? jeab_biz_doc_names() : ['code_people', 'reminder_ort_cache', 'reminders_prune', 'webshop_catchup'];
    $bundled = false;
    foreach ($names as $name) {
        if (isset($source[$name]) && is_array($source[$name])) {
            $bundled = true;
            break;
        }
    }
    $rows = [];
    $stamp = '1970-01-01 00:00:00';
    foreach ($names as $name) {
        $body = [];
        if ($bundled && isset($source[$name]) && is_array($source[$name])) {
            $body = $source[$name];
        } elseif (!$bundled && function_exists('jeab_biz_doc_json_read')) {
            $body = jeab_biz_doc_json_read($name);
        }
        $payload = json_encode(jeab_migrate_sort($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rows[] = [
            'doc_key' => function_exists('jeab_biz_doc_sql_key') ? jeab_biz_doc_sql_key($name) : 'biz_' . $name,
            'payload' => is_string($payload) ? $payload : '{}',
            'updated_at' => jeab_migrate_datetime($body['updated_at'] ?? null) ?? $stamp,
        ];
    }
    return jeab_migrate_project($rows, 'doc_key', jeab_migrate_biz_docs_module()['columns']);
}

function jeab_migrate_biz_docs_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_biz_docs_rows($source));
}
