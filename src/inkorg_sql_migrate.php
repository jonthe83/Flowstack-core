<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/json_sql_roll.php';

/**
 * Hookar: inbox.sql_migrate, inbox.sql_verify, inbox.sql_cutover.
 * De är avstängda. SQL är enda källan och JSON-filerna läses inte.
 */

const JEAB_INKORG_MESSAGES_FILE = 'messages.json';
const JEAB_INKORG_THREADS_FILE = 'threads.json';

/** @return array{ok:false,error:string,dry:bool} */
function jeab_inkorg_sql_retired(): array
{
    return ['ok' => false, 'error' => 'retired: SQL is sole source', 'dry' => true];
}

function jeab_inkorg_sql_dry(array $input): bool
{
    if (function_exists('jeab_kontakt_dry')) {
        return jeab_kontakt_dry($input);
    }
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];
    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/** @param mixed $value */
function jeab_inkorg_sql_canon(mixed $value): string
{
    $norm = jeab_inkorg_sql_sort($value);
    $json = json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? hash('sha256', $json) : '';
}

/** @param mixed $value */
function jeab_inkorg_sql_sort(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $isList = array_is_list($value);
    $out = [];
    foreach ($value as $key => $item) {
        $out[$key] = jeab_inkorg_sql_sort($item);
    }
    if (!$isList) {
        ksort($out, SORT_STRING);
    }
    return $out;
}

/**
 * @return array{ok:bool,error?:string,messages:array<string,array<string,mixed>>,notes:array<string,array<string,mixed>>,threads:array<string,array<string,mixed>>,duplicate_messages:int,duplicate_notes:int}
 */
function jeab_inkorg_sql_source(bool $reset = false): array
{
    static $cache = null;
    if ($reset) {
        $cache = null;
    }
    if (is_array($cache)) {
        return $cache;
    }
    $cache = jeab_inkorg_sql_retired() + [
        'messages' => [],
        'notes' => [],
        'threads' => [],
        'duplicate_messages' => 0,
        'duplicate_notes' => 0,
    ];

    return $cache;
    $snap = jeab_inkorg_json_snapshot();
    if (empty($snap['ok'])) {
        $cache = [
            'ok' => false,
            'error' => (string) ($snap['error'] ?? 'JSON går inte att läsa.'),
            'messages' => [],
            'notes' => [],
            'threads' => [],
            'duplicate_messages' => 0,
            'duplicate_notes' => 0,
        ];
        return $cache;
    }
    $messages = [];
    $notes = [];
    $dupMessages = 0;
    $dupNotes = 0;
    foreach ($snap['messages'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $note = jeab_inkorg_row_is_note($row);
        if (isset($messages[$id]) || isset($notes[$id])) {
            if ($note) {
                $dupNotes++;
            } else {
                $dupMessages++;
            }
            unset($messages[$id], $notes[$id]);
        }
        if ($note) {
            $notes[$id] = $row;
        } else {
            $messages[$id] = $row;
        }
    }
    $cache = [
        'ok' => true,
        'messages' => $messages,
        'notes' => $notes,
        'threads' => $snap['threads'],
        'duplicate_messages' => $dupMessages,
        'duplicate_notes' => $dupNotes,
    ];
    return $cache;
}

function jeab_inkorg_sql_store(): JeabPdoInkorgStore|array
{
    $pdo = jeab_inkorg_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.'];
    }
    return new JeabPdoInkorgStore($pdo);
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_inkorg_sql_sources_missing(): bool
{
    $root = function_exists('jeab_data_dir') ? jeab_data_dir() : '';
    if ($root === '') {
        return true;
    }
    $dir = $root . '/inkorg';

    return jeab_sql_json_source_missing($dir . '/messages.json', ['messages', 'notes'])
        && jeab_sql_json_source_missing($dir . '/threads.json', ['threads']);
}

function jeab_inkorg_sql_migrate_batch(array $input): array
{
    if (jeab_inkorg_sql_sources_missing()) {
        return jeab_sql_json_source_skip(jeab_inkorg_sql_dry($input), 'inbox.sql_migrate');
    }
    unset($input);

    return jeab_inkorg_sql_retired();
    $dry = jeab_inkorg_sql_dry($input);
    $entity = strtolower(trim((string) ($input['entity'] ?? 'all')));
    if (!in_array($entity, ['all', 'messages', 'threads', 'notes'], true)) {
        return ['ok' => false, 'error' => 'entity ska vara all, messages, threads eller notes.', 'dry' => $dry];
    }
    $phase = strtolower(trim((string) ($input['phase'] ?? '')));
    if ($phase === '') {
        $phase = $entity === 'threads' ? 'threads' : 'messages';
    }
    if (!in_array($phase, ['messages', 'threads'], true)) {
        return ['ok' => false, 'error' => 'phase ska vara messages eller threads.', 'dry' => $dry];
    }
    if ($entity === 'notes') {
        $phase = 'messages';
    }
    $offset = max(0, (int) ($input['offset'] ?? 0));
    $batchSize = (int) ($input['batch_size'] ?? JEAB_INKORG_MIGRATE_DEFAULT_BATCH);
    $batchSize = max(50, min(2000, $batchSize));
    $store = jeab_inkorg_sql_store();
    if (!$store instanceof JeabPdoInkorgStore) {
        return $store + ['dry' => $dry];
    }
    $source = jeab_inkorg_sql_source(!empty($input['reset_cache']));
    if (empty($source['ok'])) {
        return ['ok' => false, 'dry' => $dry, 'error' => $source['error'] ?? 'JSON går inte att läsa.'];
    }

    if ($phase === 'threads') {
        $ids = array_keys($source['threads']);
        sort($ids, SORT_STRING);
        $sliceIds = array_slice($ids, $offset, $batchSize);
        $slice = [];
        foreach ($sliceIds as $id) {
            $slice[$id] = $source['threads'][$id];
        }
        $written = !$dry && $slice !== [] ? $store->upsertThreadBatch($slice) : 0;
        $next = $offset + count($sliceIds);
        $done = $next >= count($ids) || $sliceIds === [];
        return [
            'ok' => true,
            'dry' => $dry,
            'entity' => $entity,
            'phase' => 'threads',
            'offset' => $offset,
            'batch_size' => $batchSize,
            'batch_count' => count($sliceIds),
            'notes_in_batch' => 0,
            'total' => count($ids),
            'next_offset' => $done ? null : $next,
            'next_phase' => null,
            'done' => $done,
            'written' => $written,
        ];
    }

    $pool = $source['messages'];
    if ($entity !== 'notes') {
        foreach ($source['notes'] as $id => $row) {
            $pool[$id] = $row;
        }
    } else {
        $pool = $source['notes'];
    }
    $ids = array_keys($pool);
    sort($ids, SORT_STRING);
    $sliceIds = array_slice($ids, $offset, $batchSize);
    $slice = [];
    $notesInBatch = 0;
    foreach ($sliceIds as $id) {
        $slice[] = $pool[$id];
        if (jeab_inkorg_row_is_note($pool[$id])) {
            $notesInBatch++;
        }
    }
    $written = !$dry && $slice !== [] ? $store->upsertMessageBatch($slice) : 0;
    $next = $offset + count($sliceIds);
    $phaseDone = $next >= count($ids) || $sliceIds === [];
    $continueThreads = $entity === 'all' && $phaseDone;
    return [
        'ok' => true,
        'dry' => $dry,
        'entity' => $entity,
        'phase' => 'messages',
        'offset' => $offset,
        'batch_size' => $batchSize,
        'batch_count' => count($sliceIds),
        'notes_in_batch' => $notesInBatch,
        'total' => count($ids),
        'next_offset' => $phaseDone ? ($continueThreads ? 0 : null) : $next,
        'next_phase' => $continueThreads ? 'threads' : null,
        'done' => $phaseDone && !$continueThreads,
        'written' => $written,
    ];
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_inkorg_sql_migrate_all(array $input): array
{
    if (jeab_inkorg_sql_sources_missing()) {
        return jeab_sql_json_source_skip(jeab_inkorg_sql_dry($input), 'inbox.sql_migrate');
    }
    unset($input);

    return jeab_inkorg_sql_retired();
    $dry = jeab_inkorg_sql_dry($input);
    $input['reset_cache'] = 1;
    $input['dry'] = $dry ? 1 : 0;
    $phase = strtolower(trim((string) ($input['phase'] ?? '')));
    if ($phase === '') {
        $entity = strtolower(trim((string) ($input['entity'] ?? 'all')));
        $phase = $entity === 'threads' ? 'threads' : 'messages';
    }
    $offset = max(0, (int) ($input['offset'] ?? 0));
    $batches = 0;
    $written = 0;
    $done = false;
    do {
        if ($batches > 5000) {
            return ['ok' => false, 'dry' => $dry, 'error' => 'För många batchar.'];
        }
        $batch = jeab_inkorg_sql_migrate_batch([
            'entity' => $input['entity'] ?? 'all',
            'phase' => $phase,
            'offset' => $offset,
            'batch_size' => $input['batch_size'] ?? JEAB_INKORG_MIGRATE_DEFAULT_BATCH,
            'dry' => $dry ? 1 : 0,
        ]);
        if (empty($batch['ok'])) {
            return $batch;
        }
        $batches++;
        $written += (int) ($batch['written'] ?? 0);
        $done = !empty($batch['done']);
        $phase = (string) ($batch['next_phase'] ?? $phase);
        $offset = (int) ($batch['next_offset'] ?? 0);
        if (!$done && ($batch['next_phase'] ?? null) === null && ($batch['next_offset'] ?? null) === null) {
            break;
        }
    } while (!$done);

    $purgedMessages = 0;
    $purgedThreads = 0;
    if (!$dry) {
        $store = jeab_inkorg_sql_store();
        $source = jeab_inkorg_sql_source(false);
        if ($store instanceof JeabPdoInkorgStore && !empty($source['ok'])) {
            $entity = strtolower(trim((string) ($input['entity'] ?? 'all')));
            if ($entity === 'all' || $entity === 'messages' || $entity === 'notes') {
                $keep = $entity === 'notes' ? array_keys($source['notes']) : array_merge(array_keys($source['messages']), array_keys($source['notes']));
                if ($entity !== 'notes') {
                    $purgedMessages = $store->purgeMissing('inkorg_messages', $keep);
                }
            }
            if ($entity === 'all' || $entity === 'threads') {
                $purgedThreads = $store->purgeMissing('inkorg_threads', array_keys($source['threads']));
            }
        }
    }

    return [
        'ok' => true,
        'dry' => $dry,
        'entity' => strtolower(trim((string) ($input['entity'] ?? 'all'))),
        'batches' => $batches,
        'written' => $written,
        'purged_messages' => $purgedMessages,
        'purged_threads' => $purgedThreads,
        'done' => true,
    ];
}

/**
 * @param list<string> $keys
 * @return list<string>
 */
function jeab_inkorg_sql_sample_keys(array $keys, int $n): array
{
    $keys = array_values($keys);
    $count = count($keys);
    if ($count === 0 || $n <= 0) {
        return [];
    }
    if ($count <= $n) {
        return $keys;
    }
    $out = [];
    $last = $n - 1;
    for ($i = 0; $i < $n; $i++) {
        $idx = (int) floor($i * ($count - 1) / $last);
        $out[$keys[$idx]] = $keys[$idx];
    }
    return array_values($out);
}

/**
 * @param array<string,array<string,mixed>> $jsonById
 * @param array<string,array<string,mixed>> $sqlById
 * @return array{match:bool,rows:list<array{id:string,match:bool}>}
 */
function jeab_inkorg_sql_sample(array $jsonById, array $sqlById, int $n): array
{
    $rows = [];
    $ok = true;
    foreach (jeab_inkorg_sql_sample_keys(array_keys($jsonById), $n) as $id) {
        $match = jeab_inkorg_sql_canon($jsonById[$id] ?? null) !== ''
            && jeab_inkorg_sql_canon($jsonById[$id]) === jeab_inkorg_sql_canon($sqlById[$id] ?? null);
        if (!$match) {
            $ok = false;
        }
        $rows[] = ['id' => $id, 'match' => $match];
    }
    return ['match' => $ok, 'rows' => $rows];
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_inkorg_sql_verify(array $input): array
{
    unset($input);

    return jeab_inkorg_sql_retired();
    $dry = true;
    unset($input['dry']);
    $sampleN = (int) ($input['sample'] ?? 5);
    $sampleN = max(1, min(50, $sampleN));
    $store = jeab_inkorg_sql_store();
    if (!$store instanceof JeabPdoInkorgStore) {
        return $store + ['dry' => $dry];
    }
    $source = jeab_inkorg_sql_source(true);
    if (empty($source['ok'])) {
        return ['ok' => false, 'dry' => $dry, 'error' => $source['error'] ?? 'JSON går inte att läsa.'];
    }
    $jsonMessages = count($source['messages']);
    $jsonNotes = count($source['notes']);
    $jsonThreads = count($source['threads']);
    $sqlMessages = $store->countMessages(false);
    $sqlNotes = $store->countMessages(true);
    $sqlThreads = $store->countThreads();
    $messageSample = jeab_inkorg_sql_sample($source['messages'], $store->messagesByIds(jeab_inkorg_sql_sample_keys(array_keys($source['messages']), $sampleN)), $sampleN);
    $noteSample = jeab_inkorg_sql_sample($source['notes'], $store->messagesByIds(jeab_inkorg_sql_sample_keys(array_keys($source['notes']), $sampleN)), $sampleN);
    $threadSample = jeab_inkorg_sql_sample($source['threads'], $store->threadsByIds(jeab_inkorg_sql_sample_keys(array_keys($source['threads']), $sampleN)), $sampleN);
    $countsMatch = $jsonMessages === $sqlMessages && $jsonNotes === $sqlNotes && $jsonThreads === $sqlThreads;
    $samplesMatch = $messageSample['match'] && $noteSample['match'] && $threadSample['match'];
    $dups = (int) $source['duplicate_messages'] + (int) $source['duplicate_notes'];
    return [
        'ok' => true,
        'dry' => $dry,
        'match' => $countsMatch && $samplesMatch && $dups === 0,
        'json_messages' => $jsonMessages,
        'sql_messages' => $sqlMessages,
        'messages_count_match' => $jsonMessages === $sqlMessages,
        'json_notes' => $jsonNotes,
        'sql_notes' => $sqlNotes,
        'notes_count_match' => $jsonNotes === $sqlNotes,
        'json_threads' => $jsonThreads,
        'sql_threads' => $sqlThreads,
        'threads_count_match' => $jsonThreads === $sqlThreads,
        'duplicate_messages' => (int) $source['duplicate_messages'],
        'duplicate_notes' => (int) $source['duplicate_notes'],
        'samples_match' => $samplesMatch,
        'samples' => [
            'messages' => $messageSample['rows'],
            'notes' => $noteSample['rows'],
            'threads' => $threadSample['rows'],
        ],
        'storage' => jeab_inkorg_storage_mode(),
    ];
}

function jeab_inkorg_sql_set_storage(string $mode): bool
{
    $pdo = jeab_inkorg_store_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $ok = jeab_settings_put($pdo, $driver, JEAB_INKORG_STORAGE_KEY, $mode === 'sql' ? 'sql' : 'json');
    if (function_exists('jeab_inkorg_json_side_reset')) {
        jeab_inkorg_json_side_reset();
    }
    jeab_inkorg_pdo_store();
    return $ok;
}

function jeab_inkorg_sql_write_file(string $name, array $doc): void
{
    unset($name, $doc);
    throw new RuntimeException('retired: SQL is sole source');
    if ($name !== 'messages.json' && $name !== 'threads.json') {
        throw new RuntimeException('Okänd inkorgsfil.');
    }
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException('Kunde inte skriva ' . $name);
    }
    $dir = jeab_inkorg_dir();
    if ($name === 'messages.json') {
        if (file_put_contents($dir . '/messages.json', $json) === false) {
            throw new RuntimeException('Kunde inte skriva messages.json');
        }
        return;
    }
    if (file_put_contents($dir . '/threads.json', $json) === false) {
        throw new RuntimeException('Kunde inte skriva threads.json');
    }
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_inkorg_sql_cutover(array $input): array
{
    unset($input);

    return jeab_inkorg_sql_retired();
    $dry = jeab_inkorg_sql_dry($input);
    $enable = !array_key_exists('enable', $input) ? null : !($input['enable'] === false || $input['enable'] === 0 || $input['enable'] === '0' || $input['enable'] === 'false');
    $before = jeab_inkorg_storage_mode();
    if ($enable === null) {
        return ['ok' => true, 'dry' => $dry, 'storage' => $before, 'changed' => false];
    }
    if ($enable) {
        $verify = jeab_inkorg_sql_verify($input);
        if (empty($verify['ok'])) {
            return $verify + ['dry' => $dry, 'storage' => $before];
        }
        if (empty($verify['match']) && empty($input['force'])) {
            return [
                'ok' => false,
                'dry' => $dry,
                'error' => 'Verifieringen matchar inte. Cutover är inte gjord.',
                'storage' => $before,
                'verify' => [
                    'match' => false,
                    'messages_count_match' => $verify['messages_count_match'] ?? false,
                    'notes_count_match' => $verify['notes_count_match'] ?? false,
                    'threads_count_match' => $verify['threads_count_match'] ?? false,
                    'samples_match' => $verify['samples_match'] ?? false,
                ],
            ];
        }
        if (!$dry) {
            if (!jeab_inkorg_sql_set_storage('sql')) {
                return ['ok' => false, 'dry' => false, 'error' => 'Flaggan kunde inte sättas.', 'storage' => jeab_inkorg_storage_mode()];
            }
        }
        return [
            'ok' => true,
            'dry' => $dry,
            'enable' => 1,
            'storage' => $dry ? $before : 'sql',
            'would_storage' => 'sql',
            'changed' => !$dry && $before !== 'sql',
        ];
    }

    $store = jeab_inkorg_sql_store();
    if (!$store instanceof JeabPdoInkorgStore) {
        return $store + ['dry' => $dry, 'storage' => $before];
    }
    $sqlMessages = $store->countMessages(false) + $store->countMessages(true);
    $sqlThreads = $store->countThreads();
    $source = jeab_inkorg_sql_source(true);
    $jsonRows = !empty($source['ok']) ? count($source['messages']) + count($source['notes']) + count($source['threads']) : 0;
    if ($before !== 'sql') {
        return ['ok' => true, 'dry' => $dry, 'enable' => 0, 'storage' => 'json', 'changed' => false, 'rollback' => 'already_json'];
    }
    if ($sqlMessages + $sqlThreads === 0 && $jsonRows > 0 && empty($input['force'])) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'SQL är tom. JSON skrevs inte över.', 'storage' => $before];
    }
    if (!$dry) {
        $messages = $store->listMessages();
        usort($messages, fn($a, $b) => strcmp((string) ($b['received_at'] ?? ''), (string) ($a['received_at'] ?? '')));
        jeab_inkorg_sql_write_file('messages.json', ['messages' => $messages]);
        jeab_inkorg_sql_write_file('threads.json', ['threads' => $store->threadsMap()]);
        if (!jeab_inkorg_sql_set_storage('json')) {
            return ['ok' => false, 'dry' => false, 'error' => 'JSON skrevs men flaggan kunde inte slås av.', 'storage' => jeab_inkorg_storage_mode()];
        }
    }
    return [
        'ok' => true,
        'dry' => $dry,
        'enable' => 0,
        'rollback' => 'json',
        'storage' => $dry ? $before : 'json',
        'would_storage' => 'json',
        'sql_messages' => $sqlMessages,
        'sql_threads' => $sqlThreads,
        'changed' => !$dry,
    ];
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_inkorg_sql_migrate_hook(array $input): array
{
    if (!empty($input['all'])) {
        return jeab_inkorg_sql_migrate_all($input);
    }
    return jeab_inkorg_sql_migrate_batch($input);
}
