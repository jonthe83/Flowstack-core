<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

/**
 * Kontakter JSON → SQL. dry om inte --write.
 *
 *   php scripts/migrate-kontakter-sql.php
 *   php scripts/migrate-kontakter-sql.php --write
 *   php scripts/migrate-kontakter-sql.php --verify
 *   php scripts/migrate-kontakter-sql.php --cutover --write
 *   php scripts/migrate-kontakter-sql.php --rollback --write
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$args = $argv ?? [];
$write = in_array('--write', $args, true);
$verify = in_array('--verify', $args, true);
$cutover = in_array('--cutover', $args, true);
$rollback = in_array('--rollback', $args, true);

if ($verify) {
    $result = jeab_kontakter_sql_verify([]);
} elseif ($cutover || $rollback) {
    $result = jeab_kontakter_sql_cutover([
        'enable' => $rollback ? 0 : 1,
        'dry' => $write ? 0 : 1,
    ]);
} else {
    $result = jeab_kontakter_sql_migrate(['dry' => $write ? 0 : 1, 'entity' => 'all']);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
exit(!empty($result['ok']) ? 0 : 1);
