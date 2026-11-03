<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/solkoll.json och data/solkoll-cache.json → solkoll_docs.
 * data/secrets/solkoll.json lämnas. api_key tas bort ur payload.
 */

function jeab_migrate_solkoll_module(): array
{
    $schema = jeab_migrate_doc_schema('solkoll_docs', 'doc_key', 'VARCHAR(32)', 'TEXT');

    return jeab_migrate_declare(
        'solkoll',
        'solkoll_docs',
        'data/solkoll.json',
        'doc_key',
        ['doc_key', 'payload', 'updated_at'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @return array<string, mixed> */
function jeab_migrate_solkoll_source(): array
{
    $out = [];
    foreach (['config' => 'solkoll.json', 'cache' => 'solkoll-cache.json'] as $slot => $file) {
        $path = function_exists('jeab_data_file') ? jeab_data_file($file) : '';
        if ($path === '' || !is_file($path)) {
            continue;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $out[$slot] = $decoded;
        }
    }

    return $out;
}

/** @param array<string, mixed> $source */
function jeab_migrate_solkoll_rows(array $source): array
{
    $rows = [];
    foreach (['config', 'cache'] as $slot) {
        if (!isset($source[$slot]) || !is_array($source[$slot])) {
            continue;
        }
        $body = $source[$slot];
        foreach (['api_key', 'api_secret', 'token', 'client_secret', 'password'] as $secret) {
            unset($body[$secret]);
        }
        $updated = jeab_migrate_datetime($body['updated_at'] ?? ($body['at'] ?? null)) ?? '1970-01-01 00:00:00';
        unset($body['updated_at']);
        $rows[] = [
            'doc_key' => $slot,
            'payload' => jeab_migrate_payload($body),
            'updated_at' => $updated,
        ];
    }

    return jeab_migrate_project($rows, 'doc_key', jeab_migrate_solkoll_module()['columns']);
}

function jeab_migrate_solkoll_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_solkoll_rows($source));
}
