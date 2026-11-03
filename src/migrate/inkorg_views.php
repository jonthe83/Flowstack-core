<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * Vyer ur data/inkorg/rules.json. Listor och etiketter i samma fil lämnas.
 * position behåller menyns ordning.
 */

function jeab_migrate_inkorg_views_module(): array
{
    return jeab_migrate_declare(
        'inkorg_views',
        'inkorg_views',
        'data/inkorg/rules.json',
        'view_id',
        ['view_id', 'name', 'position', 'payload', 'filter', 'priority'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_views (
    view_id VARCHAR(64) NOT NULL,
    name VARCHAR(80) NOT NULL,
    position INT NOT NULL,
    payload LONGTEXT NOT NULL,
    filter LONGTEXT NOT NULL,
    priority INT NOT NULL DEFAULT 0,
    PRIMARY KEY (view_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_views (
    view_id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    position INTEGER NOT NULL,
    payload TEXT NOT NULL,
    filter TEXT NOT NULL DEFAULT '',
    priority INTEGER NOT NULL DEFAULT 0
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_inkorg_views_rows(array $source): array
{
    if (isset($source['views']) && is_array($source['views'])) {
        $views = array_values($source['views']);
    } elseif (array_is_list($source)) {
        $views = $source;
    } else {
        $views = [];
    }
    $rows = [];
    $pos = 0;
    foreach ($views as $view) {
        if (!is_array($view)) {
            continue;
        }
        $id = trim((string) ($view['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $filter = is_array($view['filter'] ?? null) ? $view['filter'] : [];
        $filterJson = json_encode($filter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $priority = $view['priority'] ?? ($filter['priority'] ?? 0);
        $rows[] = [
            'view_id' => $id,
            'name' => trim((string) ($view['name'] ?? '')),
            'position' => $pos,
            'payload' => jeab_migrate_payload($view),
            'filter' => is_string($filterJson) ? $filterJson : '',
            'priority' => (int) $priority,
        ];
        $pos++;
    }

    return jeab_migrate_project($rows, 'view_id', jeab_migrate_inkorg_views_module()['columns']);
}

function jeab_migrate_inkorg_views_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_inkorg_views_rows($source));
}
