<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/external-queue.json → external_queue_jobs. Ett jobb per rad, nyckel är checksumma av jobbet.
 */

function jeab_migrate_external_queue_module(): array
{
    return jeab_migrate_declare(
        'external_queue',
        'external_queue_jobs',
        'data/external-queue.json',
        'job_key',
        ['job_key', 'kind', 'queued_at', 'seq', 'payload', 'attempts'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS external_queue_jobs (
    job_key CHAR(64) NOT NULL,
    kind VARCHAR(64) NOT NULL,
    queued_at DATETIME NOT NULL,
    seq INT NOT NULL DEFAULT 0,
    payload LONGTEXT NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    claimed_until DATETIME NULL,
    claim_token VARCHAR(64) NULL,
    PRIMARY KEY (job_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS external_queue_jobs (
    job_key TEXT PRIMARY KEY,
    kind TEXT NOT NULL,
    queued_at TEXT NOT NULL,
    seq INTEGER NOT NULL DEFAULT 0,
    payload TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    claimed_until TEXT NULL,
    claim_token TEXT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_external_queue_rows(array $source): array
{
    $jobs = $source['jobs'] ?? [];
    if (!is_array($jobs)) {
        return [];
    }
    $rows = [];
    foreach (array_values($jobs) as $index => $job) {
        if (!is_array($job)) {
            continue;
        }
        $clean = jeab_migrate_redact($job);
        if (!is_array($clean)) {
            continue;
        }
        $attempts = (int) ($job['attempts'] ?? $clean['attempts'] ?? 0);
        if ($attempts < 0) {
            $attempts = 0;
        }
        unset($clean['seq'], $clean['_seq'], $clean['attempts']);
        $payload = jeab_migrate_payload($clean);
        $rows[] = [
            'job_key' => hash('sha256', $payload),
            'kind' => jeab_migrate_text((string) ($clean['kind'] ?? '')) ?? 'job',
            'queued_at' => jeab_migrate_datetime($clean['at'] ?? null) ?? '1970-01-01 00:00:00',
            'seq' => (int) $index,
            'payload' => $payload,
            'attempts' => $attempts,
        ];
    }
    return jeab_migrate_project($rows, 'job_key', jeab_migrate_external_queue_module()['columns']);
}

function jeab_migrate_external_queue_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_external_queue_rows($source));
}

/**
 * Idempotent ALTER. Körs utanför transaktion (ALTER committar implicit på MySQL).
 */
function jeab_migrate_external_queue_add_column(PDO $pdo, string $name, string $mysqlType, string $sqliteType): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $table = function_exists('jeab_migrate_ident') ? jeab_migrate_ident($pdo, 'external_queue_jobs') : 'external_queue_jobs';
    $column = function_exists('jeab_migrate_ident') ? jeab_migrate_ident($pdo, $name) : $name;
    $type = $mysql ? $mysqlType : $sqliteType;
    try {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') !== false
            || stripos($msg, 'already exists') !== false
            || stripos($msg, 'duplicate column') !== false) {
            return;
        }
        throw $e;
    }
}

/**
 * Befintlig tabell utan attempts får kolumnen. Idempotent: dubblett ignoreras.
 */
function jeab_migrate_external_queue_ensure_attempts(PDO $pdo): void
{
    jeab_migrate_external_queue_add_column($pdo, 'attempts', 'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
}

/**
 * Befintlig tabell utan claimed_until får kolumnen. Idempotent: dubblett ignoreras.
 */
function jeab_migrate_external_queue_ensure_claimed_until(PDO $pdo): void
{
    jeab_migrate_external_queue_add_column($pdo, 'claimed_until', 'DATETIME NULL', 'TEXT NULL');
}

/**
 * Befintlig tabell utan claim_token får kolumnen. Idempotent: dubblett ignoreras.
 */
function jeab_migrate_external_queue_ensure_claim_token(PDO $pdo): void
{
    jeab_migrate_external_queue_add_column($pdo, 'claim_token', 'VARCHAR(64) NULL', 'TEXT NULL');
}
