<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/datastore.php';

/**
 * Bakgrundsjobb för data.migrate_all och kontakter.conflicts.
 * Hooken svarar med job_id och kör sedan vidare. data.job_status läser resultatet.
 * Ett jobb per typ åt gången. Ett avbrutet svep kan tas upp igen.
 */

/** Ett tyst jobb räknas som dött efter 15 minuter, samma budget som svepet. */
const JEAB_DATA_JOB_STALE_SEC = 900;

function jeab_data_job_now(): string
{
    return date('Y-m-d H:i:s');
}

function jeab_data_job_fresh(string $heartbeat): bool
{
    $beat = strtotime($heartbeat);
    if ($beat === false) {
        return false;
    }

    return $beat >= time() - JEAB_DATA_JOB_STALE_SEC;
}

function jeab_data_job_json(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '{}';
}

/** @return mixed */
function jeab_data_job_public(mixed $value): mixed
{
    if (is_string($value)) {
        return preg_replace('/\d{10,}/', '######', $value) ?? $value;
    }
    if (!is_array($value)) {
        return $value;
    }
    $drop = ['samples', 'before_json', 'after_json', 'personnummer', 'pnr', 'SocialSecurityNumber', 'OrganisationNumber'];
    $out = [];
    foreach ($value as $key => $item) {
        if (in_array((string) $key, $drop, true)) {
            continue;
        }
        $out[$key] = jeab_data_job_public($item);
    }

    return $out;
}

/**
 * Skickar svaret och låter skriptet fortsätta.
 * PHP-FPM: fastcgi_finish_request(). Annars ignore_user_abort och obegränsad tid.
 */
function jeab_data_job_detach(string $body): void
{
    @ignore_user_abort(true);
    @set_time_limit(0);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
    }
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }
    if (PHP_SAPI !== 'cli') {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }
}

/** @param array<string, mixed> $result */
function jeab_data_job_respond(array $result): never
{
    $background = !empty($result['background']);
    $jobId = (string) ($result['job_id'] ?? '');
    unset($result['background']);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    $body = jeab_data_job_json($result);
    if (!$background || $jobId === '') {
        echo $body;
        exit;
    }
    jeab_data_job_detach($body);
    try {
        jeab_data_job_run($jobId);
    } catch (Throwable) {
    }
    exit;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_data_job_accept(string $type, array $input): array
{
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'status' => 400];
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $ownTx = false;
    try {
        jeab_ensure_data_jobs($pdo, $driver);
        $ownTx = jeab_data_job_tx_begin($pdo);
        $now = jeab_data_job_now();
        $lock = jeab_data_job_lock_row($pdo, $type, true);
        if (is_array($lock) && jeab_data_job_fresh((string) ($lock['heartbeat_at'] ?? ''))) {
            jeab_data_job_tx_finish($pdo, $ownTx, true);

            return [
                'ok' => false,
                'error' => 'Ett jobb av samma typ kör redan.',
                'job_id' => (string) ($lock['job_id'] ?? ''),
                'state' => 'running',
                'status' => 409,
            ];
        }
        $open = jeab_data_job_open($pdo, $type);
        $resumed = is_array($open);
        if ($resumed) {
            $jobId = (string) ($open['job_id'] ?? '');
            $pdo->prepare('UPDATE data_jobs SET heartbeat_at = ?, updated_at = ? WHERE job_id = ?')->execute([$now, $now, $jobId]);
        } else {
            $jobId = bin2hex(random_bytes(16));
            $pdo->prepare(
                'INSERT INTO data_jobs (job_id, job_type, status, progress_json, state_json, result_json, payload_json, heartbeat_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $jobId,
                $type,
                'queued',
                '{}',
                '',
                '',
                jeab_data_job_json($input),
                $now,
                $now,
                $now,
            ]);
        }
        jeab_data_job_lock_write($pdo, $driver, $type, $jobId, $now);
        jeab_data_job_tx_finish($pdo, $ownTx, true);
    } catch (Throwable) {
        jeab_data_job_tx_finish($pdo, $ownTx, false);

        return ['ok' => false, 'error' => 'Jobbet gick inte att starta.', 'status' => 400];
    }

    return [
        'ok' => true,
        'job_id' => $jobId,
        'state' => $resumed ? 'running' : 'queued',
        'resumed' => $resumed,
        'status' => 200,
        'background' => true,
    ];
}

function jeab_data_job_run(string $jobId): void
{
    @ignore_user_abort(true);
    @set_time_limit(0);
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO || !preg_match('/^[a-f0-9]{32}$/', $jobId)) {
        return;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_data_jobs($pdo, $driver);
    $job = jeab_data_job_row($pdo, $jobId);
    if ($job === null) {
        return;
    }
    $type = (string) ($job['job_type'] ?? '');
    $status = (string) ($job['status'] ?? '');
    if ($status === 'done' || $status === 'error' || $status === 'cancelled') {
        return;
    }
    $lock = jeab_data_job_lock_row($pdo, $type);
    if (is_array($lock) && (string) ($lock['job_id'] ?? '') !== $jobId && jeab_data_job_fresh((string) ($lock['heartbeat_at'] ?? ''))) {
        return;
    }
    $owns = false;
    $state = [];
    try {
        if (!jeab_data_job_claim($pdo, $jobId) || !jeab_data_job_heartbeat($pdo, $driver, $type, $jobId)) {
            return;
        }
        $owns = true;
        if ($type === 'kontakter.conflicts') {
            jeab_data_job_run_conflicts($pdo, $driver, $jobId, $job);
            return;
        }
        if ($type !== 'data.migrate_all') {
            jeab_data_job_fail($pdo, $jobId, 'Okänd jobbtyp.');
            return;
        }
        $decoded = json_decode((string) ($job['state_json'] ?? ''), true);
        if (!is_array($decoded) || !isset($decoded['phase'])) {
            $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
            $state = jeab_data_migrate_initial(is_array($payload) ? $payload : []);
        } else {
            $state = $decoded;
        }
        if (!empty($state['held']) && empty($state['released']) && empty($state['done']) && empty($state['dry'])) {
            if (!jeab_data_maintenance()) {
                jeab_data_maintenance_set(true);
            } else {
                jeab_data_maintenance_touch();
            }
            $GLOBALS['_JEAB_DATA_MIGRATE'] = true;
            $GLOBALS['_JEAB_DATA_MAINTENANCE'] = true;
            $GLOBALS['_JEAB_DATA_JOB_HOLDING_MAINTENANCE'] = true;
            $GLOBALS['_JEAB_DATA_JOB_MAINTENANCE_WAS'] = !empty($state['maintenance_was']);
            jeab_data_maintenance_arm_shutdown();
        }
        $store = null;
        if (function_exists('jeab_store')) {
            try {
                $opened = jeab_store();
                $store = $opened instanceof JeabStore ? $opened : null;
            } catch (Throwable) {
                $store = null;
            }
        }
        $taken = 0;
        do {
            if (!jeab_data_job_heartbeat($pdo, $driver, $type, $jobId)) {
                return;
            }
            $state = jeab_data_migrate_step($state, $store);
            $taken++;
            if (!jeab_data_job_save($pdo, $jobId, $state)) {
                return;
            }
            $max = (int) ($GLOBALS['_JEAB_DATA_JOB_MAX_STEPS'] ?? 0);
            if ($max > 0 && $taken >= $max && empty($state['done'])) {
                return;
            }
        } while (empty($state['done']));
    } catch (Throwable $e) {
        if ($state !== []) {
            jeab_data_migrate_release($state);
        }
        jeab_data_job_fail($pdo, $jobId, $e->getMessage());
    } finally {
        if ($owns) {
            jeab_data_job_lock_release($pdo, $type, $jobId);
        }
    }
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_data_job_status_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $jobId = trim((string) ($input['job_id'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
        return ['ok' => false, 'error' => 'job_id saknas.', 'status' => 400];
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'status' => 400];
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_data_jobs($pdo, $driver);
    $row = jeab_data_job_row($pdo, $jobId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Jobbet finns inte.', 'status' => 404];
    }
    $progress = json_decode((string) ($row['progress_json'] ?? ''), true);
    $result = null;
    $state = (string) ($row['status'] ?? '');
    if ($state === 'done' || $state === 'error') {
        $decoded = json_decode((string) ($row['result_json'] ?? ''), true);
        $result = is_array($decoded) ? $decoded : ['ok' => false];
    }

    return [
        'ok' => true,
        'job_id' => $jobId,
        'state' => $state,
        'progress' => is_array($progress) ? $progress : [],
        'result' => $result,
        'status' => 200,
    ];
}

/** @param array<string, mixed> $job */
function jeab_data_job_run_conflicts(PDO $pdo, string $driver, string $jobId, array $job): void
{
    require_once __DIR__ . '/kontakter_conflicts.php';
    $decoded = json_decode((string) ($job['state_json'] ?? ''), true);
    if (is_array($decoded) && (string) ($decoded['kind'] ?? '') === 'conflicts' && empty($decoded['done'])) {
        $state = $decoded;
    } else {
        $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
        $input = is_array($payload) ? $payload : [];
        $input['action'] = 'kontakter.conflicts';
        $state = jeab_kontakter_conflicts_initial($input);
    }
    try {
        if (!jeab_data_job_save($pdo, $jobId, $state)) {
            return;
        }
        $taken = 0;
        do {
            if (!jeab_data_job_heartbeat($pdo, $driver, 'kontakter.conflicts', $jobId)) {
                return;
            }
            $state = jeab_kontakter_conflicts_chunk($state, null);
            $taken++;
            if (!jeab_data_job_save($pdo, $jobId, $state)) {
                return;
            }
            $max = (int) ($GLOBALS['_JEAB_DATA_JOB_MAX_STEPS'] ?? 0);
            if ($max > 0 && $taken >= $max && empty($state['done'])) {
                return;
            }
        } while (empty($state['done']));
    } finally {
        jeab_kontakter_conflicts_cache_clear();
    }
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_data_job_cancel_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $jobId = trim((string) ($input['job_id'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
        return ['ok' => false, 'error' => 'job_id saknas.', 'status' => 400];
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'status' => 400];
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_data_jobs($pdo, $driver);
    $ownTx = false;
    try {
        $ownTx = jeab_data_job_tx_begin($pdo);
        $row = jeab_data_job_row($pdo, $jobId, true);
        if ($row === null) {
            jeab_data_job_tx_finish($pdo, $ownTx, true);

            return ['ok' => false, 'error' => 'Jobbet finns inte.', 'status' => 404];
        }
        $status = (string) ($row['status'] ?? '');
        if ($status === 'cancelled') {
            jeab_data_job_tx_finish($pdo, $ownTx, true);

            return ['ok' => true, 'job_id' => $jobId, 'state' => 'cancelled', 'status' => 200];
        }
        if ($status === 'done' || $status === 'error') {
            jeab_data_job_tx_finish($pdo, $ownTx, true);

            return ['ok' => false, 'error' => 'Jobbet är redan klart.', 'state' => $status, 'status' => 400];
        }
        $now = jeab_data_job_now();
        $pdo->prepare('UPDATE data_jobs SET status = ?, updated_at = ? WHERE job_id = ? AND status IN (\'queued\', \'running\')')->execute(['cancelled', $now, $jobId]);
        $pdo->prepare('DELETE FROM data_job_locks WHERE job_id = ?')->execute([$jobId]);
        jeab_data_job_tx_finish($pdo, $ownTx, true);
    } catch (Throwable) {
        jeab_data_job_tx_finish($pdo, $ownTx, false);

        return ['ok' => false, 'error' => 'Jobbet gick inte att avbryta.', 'status' => 400];
    }

    return ['ok' => true, 'job_id' => $jobId, 'state' => 'cancelled', 'status' => 200];
}

/** @param array<string, mixed> $state */
function jeab_data_job_save(PDO $pdo, string $jobId, array $state): bool
{
    $done = !empty($state['done']);
    $now = jeab_data_job_now();
    if ((string) ($state['kind'] ?? '') === 'conflicts') {
        $progressValue = [
            'phase' => (string) ($state['phase'] ?? ''),
            'cursor' => (int) ($state['cursor'] ?? 0),
            'limit' => (int) ($state['limit'] ?? 0),
            'total' => (int) ($state['total'] ?? 0),
            'type' => 'kontakter.conflicts',
            'index' => (int) ($state['cursor'] ?? 0),
            'done' => $done,
        ];
    } else {
        $progressValue = jeab_data_migrate_progress($state);
    }
    $progress = jeab_data_job_json(jeab_data_job_public($progressValue));
    $stored = $state;
    unset($stored['result']);
    $stateJson = jeab_data_job_json($stored);
    if ($done) {
        $result = jeab_data_job_public($state['result'] ?? []);
        $pdo->prepare(
            'UPDATE data_jobs SET status = ?, progress_json = ?, state_json = ?, result_json = ?, heartbeat_at = ?, updated_at = ? WHERE job_id = ? AND status IN (\'queued\', \'running\')'
        )->execute(['done', $progress, $stateJson, jeab_data_job_json($result), $now, $now, $jobId]);
    } else {
        $pdo->prepare(
            'UPDATE data_jobs SET status = ?, progress_json = ?, state_json = ?, heartbeat_at = ?, updated_at = ? WHERE job_id = ? AND status IN (\'queued\', \'running\')'
        )->execute(['running', $progress, $stateJson, $now, $now, $jobId]);
    }
    $fresh = jeab_data_job_row($pdo, $jobId);
    $want = $done ? 'done' : 'running';
    $kept = is_array($fresh) && (string) ($fresh['status'] ?? '') === $want;
    if ($kept && !$done && !empty($state['held']) && empty($state['released'])) {
        jeab_data_maintenance_touch();
    }

    return $kept;
}

function jeab_data_job_fail(PDO $pdo, string $jobId, string $message): void
{
    $current = jeab_data_job_row($pdo, $jobId);
    if (is_array($current) && in_array((string) ($current['status'] ?? ''), ['cancelled', 'done'], true)) {
        return;
    }
    $clean = jeab_data_job_public(['ok' => false, 'error' => $message]);
    $now = jeab_data_job_now();
    $pdo->prepare(
        'UPDATE data_jobs SET status = ?, progress_json = ?, result_json = ?, heartbeat_at = ?, updated_at = ? WHERE job_id = ?'
    )->execute([
        'error',
        jeab_data_job_json(['phase' => 'error', 'done' => true]),
        jeab_data_job_json($clean),
        $now,
        $now,
        $jobId,
    ]);
}

/** @return array<string, mixed>|null */
function jeab_data_job_row(PDO $pdo, string $jobId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT job_id, job_type, status, progress_json, state_json, result_json, payload_json, heartbeat_at FROM data_jobs WHERE job_id = ?';
    if ($forUpdate && (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function jeab_data_job_open(PDO $pdo, string $type): ?array
{
    $stmt = $pdo->prepare(
        'SELECT job_id, status, heartbeat_at FROM data_jobs WHERE job_type = ? AND status IN (\'queued\', \'running\') ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([$type]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function jeab_data_job_lock_row(PDO $pdo, string $type, bool $forUpdate = false): ?array
{
    $sql = 'SELECT job_type, job_id, heartbeat_at FROM data_job_locks WHERE job_type = ?';
    if ($forUpdate && (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$type]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function jeab_data_job_claim(PDO $pdo, string $jobId): bool
{
    $pdo->prepare('UPDATE data_jobs SET status = ?, updated_at = ? WHERE job_id = ? AND status IN (\'queued\', \'running\')')
        ->execute(['running', jeab_data_job_now(), $jobId]);
    $row = jeab_data_job_row($pdo, $jobId);

    return is_array($row) && (string) ($row['status'] ?? '') === 'running';
}

function jeab_data_job_heartbeat(PDO $pdo, string $driver, string $type, string $jobId): bool
{
    $ownTx = false;
    try {
        $ownTx = jeab_data_job_tx_begin($pdo);
        $row = jeab_data_job_row($pdo, $jobId, true);
        $status = is_array($row) ? (string) ($row['status'] ?? '') : '';
        if ($status !== 'running') {
            jeab_data_job_tx_finish($pdo, $ownTx, true);

            return false;
        }
        $lock = jeab_data_job_lock_row($pdo, $type, true);
        if (is_array($lock) && (string) ($lock['job_id'] ?? '') !== $jobId && jeab_data_job_fresh((string) ($lock['heartbeat_at'] ?? ''))) {
            jeab_data_job_tx_finish($pdo, $ownTx, true);

            return false;
        }
        jeab_data_job_lock_write($pdo, $driver, $type, $jobId, jeab_data_job_now());
        jeab_data_job_tx_finish($pdo, $ownTx, true);
    } catch (Throwable) {
        jeab_data_job_tx_finish($pdo, $ownTx, false);

        return false;
    }

    return true;
}

function jeab_data_job_tx_begin(PDO $pdo): bool
{
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        if (jeab_pdo_in_tx($pdo)) {
            return false;
        }
        jeab_pdo_begin_immediate($pdo);

        return true;
    }
    if ($pdo->inTransaction()) {
        return false;
    }
    $pdo->beginTransaction();

    return true;
}

function jeab_data_job_tx_finish(PDO $pdo, bool $own, bool $commit): void
{
    if (!$own) {
        return;
    }
    $sqlite = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($commit) {
        // Ett misslyckat commit får inte se ut som att låset är sparat.
        if ($sqlite) {
            jeab_pdo_end($pdo, true);

            return;
        }
        if (!$pdo->inTransaction()) {
            return;
        }
        $pdo->commit();

        return;
    }
    try {
        if ($sqlite) {
            jeab_pdo_end($pdo, false);

            return;
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable) {
    }
}

function jeab_data_job_lock_write(PDO $pdo, string $driver, string $type, string $jobId, string $now): void
{
    if ($driver === 'mysql') {
        $sql = 'INSERT INTO data_job_locks (job_type, job_id, heartbeat_at) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE job_id = VALUES(job_id), heartbeat_at = VALUES(heartbeat_at)';
    } else {
        $sql = 'INSERT INTO data_job_locks (job_type, job_id, heartbeat_at) VALUES (?, ?, ?)
            ON CONFLICT(job_type) DO UPDATE SET job_id = excluded.job_id, heartbeat_at = excluded.heartbeat_at';
    }
    $pdo->prepare($sql)->execute([$type, $jobId, $now]);
}

function jeab_data_job_lock_release(PDO $pdo, string $type, string $jobId): void
{
    $pdo->prepare('DELETE FROM data_job_locks WHERE job_type = ? AND job_id = ?')->execute([$type, $jobId]);
}
