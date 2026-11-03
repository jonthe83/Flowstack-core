<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Diagnostik för Inkorgs läsväg i riktiga requests (inte proben).
 *
 * Samlar i minnet var SQL byts mot JSON, var ett fel sväljs och var lagret
 * blir null, plus en slutrad per request. Raderna skrivs vid avslut till en
 * ringbuffert (senaste JEAB_INKORG_TRACE_RING raderna) i den privata
 * datamappen. Bara när flaggan är sql och bara för inloggad p=inkorg.
 * Läses och töms med hook.php?action=data.inkorg_trace(&clear=1).
 *
 * Allt här är felsäkert: inga undantag lämnar funktionerna, ingen DDL,
 * inga nya databasanrop vid avslut, inga sökvägar, e-post eller personnamn.
 */

const JEAB_INKORG_TRACE_RING = 60;
const JEAB_INKORG_TRACE_PER_REQUEST = 14;
const JEAB_INKORG_TRACE_FILE = 'inkorg-trace.json';

function jeab_inkorg_trace_path(): string
{
    if (!function_exists('jeab_data_dir')) {
        return '';
    }

    return jeab_data_dir() . '/' . JEAB_INKORG_TRACE_FILE;
}

/** Billig koll: bara webbsidan p=inkorg, aldrig hook, cron eller tick. */
function jeab_inkorg_trace_candidate(): bool
{
    if (!empty($GLOBALS['JEAB_NO_SESSION']) || !empty($GLOBALS['_JEAB_INKORG_TRACE_OFF'])) {
        return false;
    }
    if (PHP_SAPI === 'cli' && empty($GLOBALS['_JEAB_INKORG_TRACE_CLI'])) {
        return false;
    }

    return (string) ($_GET['p'] ?? '') === 'inkorg';
}

/** @return array<string, mixed> */
function &jeab_inkorg_trace_state(): array
{
    if (!isset($GLOBALS['_JEAB_INKORG_TRACE']) || !is_array($GLOBALS['_JEAB_INKORG_TRACE'])) {
        $GLOBALS['_JEAB_INKORG_TRACE'] = [
            'events' => [],
            'src' => [],
            'modes' => [],
            'mode_first' => '',
            'mode_last' => '',
            'flips' => 0,
            'pdo_null' => 0,
            'file_flag' => '',
            'note' => [],
            'summary' => null,
            'armed' => false,
        ];
    }

    return $GLOBALS['_JEAB_INKORG_TRACE'];
}

function jeab_inkorg_trace_arm(): void
{
    $st = &jeab_inkorg_trace_state();
    if (!empty($st['armed'])) {
        return;
    }
    $st['armed'] = true;
    if (empty($GLOBALS['_JEAB_INKORG_TRACE_NO_SHUTDOWN'])) {
        register_shutdown_function('jeab_inkorg_trace_flush');
    }
}

/** Tar bort sökvägar, e-post, konton och långa sifferserier ur ett felmeddelande. */
function jeab_inkorg_trace_clean(string $text, int $max = 160): string
{
    $text = (string) preg_replace('/[\r\n\t]+/', ' ', $text);
    $text = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[e-post]', $text);
    $text = (string) preg_replace("/'[^'\\s]*'@'[^'\\s]*'/", '[konto]', $text);
    $text = (string) preg_replace('~(?:[A-Za-z]:)?(?:[\\\\/][^\\s\'"\\\\/:]+){2,}[\\\\/]?~', '[sökväg]', $text);
    $text = (string) preg_replace('/\b(user|host|dbname|password|pwd|uid)\s*=\s*[^;\s]+/i', '$1=[dold]', $text);
    $text = (string) preg_replace('/\d{7,}/', '[nr]', $text);
    $text = trim((string) preg_replace('/\s{2,}/', ' ', $text));
    if (function_exists('mb_substr')) {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    return strlen($text) > $max ? substr($text, 0, $max) . '…' : $text;
}

/** @param array<string, mixed> $ctx */
function jeab_inkorg_trace_ctx(array $ctx): array
{
    $out = [];
    foreach ($ctx as $k => $v) {
        if (count($out) >= 10) {
            break;
        }
        $key = preg_replace('/[^a-z0-9_]/i', '', (string) $k);
        if ($key === '') {
            continue;
        }
        if (is_bool($v) || is_int($v) || is_float($v) || $v === null) {
            $out[$key] = $v;
        } elseif (is_string($v)) {
            $out[$key] = jeab_inkorg_trace_clean($v, 60);
        } elseif (is_array($v)) {
            $out[$key] = count($v);
        }
    }

    return $out;
}

/**
 * En händelse: SQL byttes mot JSON, ett fel svaldes eller lagret blev null.
 * Samma plats och undantagsklass räknas upp i stället för att upprepas.
 *
 * @param array<string, mixed> $ctx
 */
function jeab_inkorg_trace_event(string $where, ?Throwable $e = null, array $ctx = []): void
{
    try {
        if (!jeab_inkorg_trace_candidate()) {
            return;
        }
        jeab_inkorg_trace_arm();
        $st = &jeab_inkorg_trace_state();
        $where = preg_replace('/[^a-z0-9_.:\-]/i', '', $where) ?: 'okand';
        $class = $e !== null ? get_class($e) : '';
        $key = $where . '|' . $class;
        if (isset($st['events'][$key])) {
            $st['events'][$key]['n']++;

            return;
        }
        if (count($st['events']) >= JEAB_INKORG_TRACE_PER_REQUEST) {
            return;
        }
        $row = ['where' => $where, 'n' => 1];
        if ($e !== null) {
            $row['ex'] = $class;
            $row['code'] = is_scalar($e->getCode()) ? (string) $e->getCode() : '';
            $row['msg'] = jeab_inkorg_trace_clean($e->getMessage());
        }
        if ($ctx !== []) {
            $row['ctx'] = jeab_inkorg_trace_ctx($ctx);
        }
        $row['ms'] = jeab_inkorg_trace_ms();
        $st['events'][$key] = $row;
    } catch (Throwable) {
    }
}

/** Vilken källa gav rader: sql_historik, sql_working, json_light, cache_swr … */
function jeab_inkorg_trace_source(string $source): void
{
    try {
        if (!jeab_inkorg_trace_candidate()) {
            return;
        }
        jeab_inkorg_trace_arm();
        $st = &jeab_inkorg_trace_state();
        $source = preg_replace('/[^a-z0-9_:\-]/i', '', $source) ?: 'okand';
        if (!in_array($source, $st['src'], true) && count($st['src']) < 16) {
            $st['src'][] = $source;
        }
    } catch (Throwable) {
    }
}

/** Fria siffror under requesten, t.ex. rows/total från prepare. */
function jeab_inkorg_trace_note(array $values): void
{
    try {
        if (!jeab_inkorg_trace_candidate()) {
            return;
        }
        $st = &jeab_inkorg_trace_state();
        foreach (jeab_inkorg_trace_ctx($values) as $k => $v) {
            $st['note'][$k] = $v;
        }
    } catch (Throwable) {
    }
}

/**
 * Anropas av jeab_inkorg_storage_mode(): vad gav flaggan, och byttes läget
 * under samma request (t.ex. när PDO försvann efter jeab_request_release).
 */
function jeab_inkorg_trace_mode(string $pdoState, string $settings, string $file, string $final): void
{
    try {
        if (!jeab_inkorg_trace_candidate()) {
            return;
        }
        jeab_inkorg_trace_arm();
        $st = &jeab_inkorg_trace_state();
        $st['modes'][$final] = (int) ($st['modes'][$final] ?? 0) + 1;
        if ($file !== '') {
            $st['file_flag'] = $file;
        }
        if ($pdoState !== 'ok') {
            $st['pdo_null']++;
        }
        if ($st['mode_first'] === '') {
            $st['mode_first'] = $final;
        } elseif ($st['mode_last'] !== $final) {
            $st['flips']++;
            jeab_inkorg_trace_event('mode_flip.' . $st['mode_last'] . '_to_' . $final, null, [
                'pdo' => $pdoState,
                'settings' => $settings,
                'file' => $file,
            ]);
        }
        if ($pdoState !== 'ok' && $final !== 'sql') {
            jeab_inkorg_trace_event('storage_mode.pdo_' . $pdoState, null, ['settings' => $settings, 'file' => $file, 'final' => $final]);
        }
        $st['mode_last'] = $final;
    } catch (Throwable) {
    }
}

function jeab_inkorg_trace_ms(): int
{
    $start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

    return (int) round((microtime(true) - $start) * 1000);
}

/** Identitet från redan uträknat minne, utan att räkna om eller spara. */
function jeab_inkorg_trace_identity(): array
{
    $forced = $GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE'] ?? null;
    $memo = is_array($forced) ? [$forced] : ($GLOBALS['_JEAB_LOGIN_IDENTITY'] ?? null);
    if (!is_array($memo) || $memo === []) {
        return ['certain' => null, 'reason' => 'ej_uträknad'];
    }
    $last = end($memo);
    if (!is_array($last)) {
        return ['certain' => null, 'reason' => 'okand'];
    }

    return [
        'certain' => !empty($last['certain']),
        'reason' => jeab_inkorg_trace_clean((string) ($last['reason'] ?? ''), 40),
    ];
}

/**
 * Slutraden från sidan. $fast är svaret från jeab_inkorg_fast_prepare()
 * eller en räknarpåse (counts/queues).
 */
function jeab_inkorg_trace_page(string $part, array $fast): void
{
    try {
        if (!jeab_inkorg_trace_candidate()) {
            return;
        }
        jeab_inkorg_trace_arm();
        $st = &jeab_inkorg_trace_state();
        $counts = is_array($fast['counts'] ?? null) ? $fast['counts'] : [];
        $queues = is_array($fast['queues'] ?? null) ? $fast['queues'] : [];
        $smallCounts = [];
        foreach ($counts as $k => $v) {
            if (count($smallCounts) >= 14) {
                break;
            }
            $smallCounts[preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)] = (int) $v;
        }
        $smallQueues = [];
        foreach ($queues as $k => $v) {
            if (count($smallQueues) >= 14) {
                break;
            }
            $smallQueues[preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)] = is_numeric($v) ? (int) $v : (is_array($v) ? count($v) : 0);
        }
        $st['summary'] = [
            'part' => preg_replace('/[^a-z]/', '', $part),
            'view' => preg_replace('/[^a-z0-9_\-]/i', '', (string) ($fast['vyId'] ?? '')),
            'scope' => preg_replace('/[^a-z0-9_\-]/i', '', (string) ($fast['scope'] ?? '')),
            'ko' => preg_replace('/[^a-z0-9_\-]/i', '', (string) ($fast['ko'] ?? '')),
            'box' => preg_replace('/[^a-z0-9_\-]/i', '', (string) ($fast['box'] ?? '')),
            'total' => isset($fast['total']) ? (int) $fast['total'] : null,
            'oppna' => array_key_exists('v-inkorg', $counts) ? (int) $counts['v-inkorg'] : null,
            'historik' => array_key_exists('v-historik', $counts) ? (int) $counts['v-historik'] : null,
            'counts' => $smallCounts,
            'queues' => $smallQueues,
            'me' => isset($fast['me']) ? ((string) $fast['me'] !== '') : null,
        ];
    } catch (Throwable) {
    }
}

/** Ska raden skrivas? Bara inloggad p=inkorg med sql-flagga. Inga nya DB-anrop. */
function jeab_inkorg_trace_should_write(array $st): bool
{
    if (!jeab_inkorg_trace_candidate()) {
        return false;
    }
    if (!function_exists('jeab_auth_ok') || !jeab_auth_ok()) {
        return false;
    }
    if (!empty($st['modes']['sql']) || ($st['file_flag'] ?? '') === 'sql') {
        return true;
    }
    // Läget blev json bara för att PDO saknades, eller requesten tog slut
    // (upptagen plats) innan läget lästes: skriv bara om loggen redan används
    // (filen finns, skapad i sql-läge eller av clear=1). Ren json-drift skriver inget.
    $unseen = empty($st['modes']) && !empty($st['events']);
    if ((int) ($st['pdo_null'] ?? 0) > 0 || $unseen) {
        $path = jeab_inkorg_trace_path();

        return $path !== '' && is_file($path);
    }

    return false;
}

/** Bygger raderna för den här requesten. @return list<array<string, mixed>> */
function jeab_inkorg_trace_lines(array $st): array
{
    $rid = substr(bin2hex(random_bytes(4)), 0, 8);
    $t = date('c');
    $lines = [];
    foreach ($st['events'] as $ev) {
        $lines[] = ['t' => $t, 'rid' => $rid, 'k' => 'event'] + $ev;
    }
    $sum = is_array($st['summary']) ? $st['summary'] : ['part' => preg_replace('/[^a-z]/', '', (string) ($_GET['ix_part'] ?? '')), 'incomplete' => true];
    $modes = [];
    foreach ((array) $st['modes'] as $m => $n) {
        $modes[(string) $m] = (int) $n;
    }
    $lines[] = ['t' => $t, 'rid' => $rid, 'k' => 'end'] + $sum + [
        'method' => preg_replace('/[^A-Z]/', '', (string) ($_SERVER['REQUEST_METHOD'] ?? '')),
        'partial' => isset($_SERVER['HTTP_X_JEAB_PARTIAL']) ? 1 : 0,
        'mode' => (string) $st['mode_last'],
        'mode_first' => (string) $st['mode_first'],
        'modes' => $modes,
        'flips' => (int) $st['flips'],
        'pdo_null' => (int) $st['pdo_null'],
        'file_flag' => (string) $st['file_flag'],
        'source' => array_values($st['src']),
        'note' => $st['note'],
        'identity' => jeab_inkorg_trace_identity(),
        'events' => count($st['events']),
        'ms' => jeab_inkorg_trace_ms(),
    ];

    return $lines;
}

/** Shutdown: lägger requestens rader i ringen. Får aldrig störa sidan. */
function jeab_inkorg_trace_flush(): void
{
    try {
        $st = $GLOBALS['_JEAB_INKORG_TRACE'] ?? null;
        if (!is_array($st) || !empty($st['flushed'])) {
            return;
        }
        $GLOBALS['_JEAB_INKORG_TRACE']['flushed'] = true;
        if (!jeab_inkorg_trace_should_write($st)) {
            return;
        }
        jeab_inkorg_trace_append(jeab_inkorg_trace_lines($st));
    } catch (Throwable) {
    }
}

/** @param list<array<string, mixed>> $lines */
function jeab_inkorg_trace_append(array $lines): bool
{
    return jeab_inkorg_trace_rewrite(static function (array $rows) use ($lines): array {
        foreach ($lines as $line) {
            $rows[] = $line;
        }

        return array_slice($rows, -JEAB_INKORG_TRACE_RING);
    });
}

/** Läser, ändrar och skriver ringen under LOCK_EX. Ger upp efter kort väntan. */
function jeab_inkorg_trace_rewrite(callable $change): bool
{
    $path = jeab_inkorg_trace_path();
    if ($path === '') {
        return false;
    }
    $fh = null;
    $old = umask(0077);
    try {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return false;
        }
        $locked = false;
        for ($i = 0; $i < 4; $i++) {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(5000);
        }
        if (!$locked) {
            return false;
        }
        $raw = stream_get_contents($fh);
        $doc = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $rows = is_array($doc) && is_array($doc['rows'] ?? null) ? array_values($doc['rows']) : [];
        $rows = $change($rows);
        $json = json_encode(['v' => 1, 'rows' => array_values($rows)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($json)) {
            flock($fh, LOCK_UN);

            return false;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json);
        fflush($fh);
        flock($fh, LOCK_UN);
        @chmod($path, 0600);

        return true;
    } catch (Throwable) {
        return false;
    } finally {
        if (is_resource($fh)) {
            fclose($fh);
        }
        umask($old);
    }
}

/** @return list<array<string, mixed>> */
function jeab_inkorg_trace_read(): array
{
    $path = jeab_inkorg_trace_path();
    if ($path === '' || !is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $doc = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

    return is_array($doc) && is_array($doc['rows'] ?? null) ? array_values($doc['rows']) : [];
}

/** Hook data.inkorg_trace: GET med token. &clear=1 tömmer ringen (filen blir kvar så loggen är på). */
function jeab_inkorg_trace_dispatch(array $input): array
{
    if (!function_exists('jeab_cutover_all_authorized') || !jeab_cutover_all_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $rows = jeab_inkorg_trace_read();
    $clear = ($input['clear'] ?? '') === '1' || ($input['clear'] ?? null) === 1 || ($input['clear'] ?? null) === true;
    $cleared = false;
    if ($clear) {
        $cleared = jeab_inkorg_trace_rewrite(static fn (array $r): array => []);
    }
    $path = jeab_inkorg_trace_path();

    return [
        'ok' => true,
        'status' => 200,
        'ring' => JEAB_INKORG_TRACE_RING,
        'armed' => $path !== '' && is_file($path),
        'count' => count($rows),
        'cleared' => $cleared,
        'rows' => $rows,
    ];
}
