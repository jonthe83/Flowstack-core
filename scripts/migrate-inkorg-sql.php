<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

/**
 * Inkorg JSON → SQL. dry om inte --write.
 *
 *   php scripts/migrate-inkorg-sql.php
 *   php scripts/migrate-inkorg-sql.php --write --all
 *   php scripts/migrate-inkorg-sql.php --verify
 *   php scripts/migrate-inkorg-sql.php --cutover --write
 *   php scripts/migrate-inkorg-sql.php --rollback --write
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$args = $argv ?? [];
$write = in_array('--write', $args, true);
$verify = in_array('--verify', $args, true);
$all = in_array('--all', $args, true);
$cutover = in_array('--cutover', $args, true);
$rollback = in_array('--rollback', $args, true);
$entity = 'all';
$offset = 0;
$batch = JEAB_INKORG_MIGRATE_DEFAULT_BATCH;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--entity=')) {
        $entity = substr($arg, 9);
    }
    if (str_starts_with($arg, '--offset=')) {
        $offset = (int) substr($arg, 9);
    }
    if (str_starts_with($arg, '--batch-size=')) {
        $batch = (int) substr($arg, 13);
    }
}

if ($verify) {
    $result = jeab_inkorg_sql_verify([]);
} elseif ($cutover || $rollback) {
    $result = jeab_inkorg_sql_cutover([
        'enable' => $rollback ? 0 : 1,
        'dry' => $write ? 0 : 1,
    ]);
} elseif ($all) {
    $result = jeab_inkorg_sql_migrate_all([
        'entity' => $entity,
        'batch_size' => $batch,
        'dry' => $write ? 0 : 1,
    ]);
} else {
    $result = jeab_inkorg_sql_migrate_batch([
        'entity' => $entity,
        'offset' => $offset,
        'batch_size' => $batch,
        'dry' => $write ? 0 : 1,
    ]);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
exit(!empty($result['ok']) ? 0 : 1);
