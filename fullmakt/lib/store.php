<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/shield.php';
jeab_deny_if_direct(__FILE__);

function fullmakt_pending_path(string $token): string
{
    return fullmakt_slot('pending/' . $token . '.json');
}

function fullmakt_signed_json_path(string $token): string
{
    return fullmakt_slot('signed/' . $token . '.json');
}

function fullmakt_signed_pdf_path(string $token): string
{
    return fullmakt_slot('signed/' . $token . '.pdf');
}

/**
 * Serve the signed PDF for a known token. Read-only. Returns false if missing.
 * Filenames stay generic. No token, path, or signing URL is written to the body.
 */
function fullmakt_serve_signed_pdf(string $token): bool
{
    if ($token === '') {
        return false;
    }
    $path = fullmakt_signed_pdf_path($token);
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return false;
    }
    fullmakt_security_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="fullmakt.pdf"');
    header('X-Robots-Tag: noindex');
    readfile($path);
    return true;
}

function fullmakt_original_pdf_path(string $token): string
{
    return fullmakt_slot('originals/' . $token . '.pdf');
}

function fullmakt_preview_png_path(string $token): string
{
    return fullmakt_slot('originals/' . $token . '.png');
}

function fullmakt_sql_active(): bool
{
    return function_exists('jeab_cutover_sql')
        && jeab_cutover_sql('fullmakt')
        && function_exists('jeab_data_pdo')
        && jeab_data_pdo() instanceof PDO
        && function_exists('jeab_data_write');
}

function fullmakt_sql_cache_forget(?string $token = null): void
{
    if ($token === null || $token === '') {
        unset($GLOBALS['_JEAB_FULLMAKT_SQL_DOC'], $GLOBALS['_JEAB_FULLMAKT_SQL_BY_STATUS']);
        return;
    }
    if (isset($GLOBALS['_JEAB_FULLMAKT_SQL_DOC']) && is_array($GLOBALS['_JEAB_FULLMAKT_SQL_DOC'])) {
        unset($GLOBALS['_JEAB_FULLMAKT_SQL_DOC'][$token]);
    }
    unset($GLOBALS['_JEAB_FULLMAKT_SQL_BY_STATUS']);
}

function fullmakt_sql_cache_store(string $token, ?array $doc, bool $wrote = false): void
{
    if ($token === '') {
        return;
    }
    if (!isset($GLOBALS['_JEAB_FULLMAKT_SQL_DOC']) || !is_array($GLOBALS['_JEAB_FULLMAKT_SQL_DOC'])) {
        $GLOBALS['_JEAB_FULLMAKT_SQL_DOC'] = [];
    }
    $GLOBALS['_JEAB_FULLMAKT_SQL_DOC'][$token] = $doc;
    if ($wrote) {
        unset($GLOBALS['_JEAB_FULLMAKT_SQL_BY_STATUS']);
    }
}

function fullmakt_pending_exists(string $token): bool
{
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token) ?? '';
    if ($token === '') {
        return false;
    }
    if (fullmakt_sql_active()) {
        $doc = fullmakt_sql_get($token);

        return is_array($doc) && ($doc['status'] ?? '') !== 'signed';
    }

    return is_file(fullmakt_pending_path($token));
}

function fullmakt_sql_put(array $doc): void
{
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($doc['token'] ?? '')) ?? '';
    if ($token === '') {
        throw new RuntimeException('Dokumentet kan inte sparas');
    }
    $doc['token'] = $token;
    $payload = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('JSON-fel');
    }
    $created = function_exists('jeab_migrate_datetime') ? jeab_migrate_datetime($doc['created_at'] ?? null) : null;
    jeab_data_write('fullmakt', [[
        'token' => $token,
        'status' => (string) ($doc['status'] ?? 'pending'),
        'created_at' => $created,
        'payload' => $payload,
    ]]);
    fullmakt_sql_cache_store($token, $doc, true);
}

/**
 * En rad, prepared. Hookar får aldrig returnera payload: svaret stannar
 * i signeringslagret. jeab_data_public tar bort nyckeln payload.
 */
function fullmakt_sql_get(string $token): ?array
{
    if (!fullmakt_sql_active() || $token === '') {
        return null;
    }
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token) ?? '';
    if ($token === '') {
        return null;
    }
    $cache = $GLOBALS['_JEAB_FULLMAKT_SQL_DOC'] ?? null;
    if (is_array($cache) && array_key_exists($token, $cache)) {
        $cached = $cache[$token];

        return is_array($cached) ? $cached : null;
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_migrate_ident')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT payload FROM ' . jeab_migrate_ident($pdo, 'fullmakt_docs') . ' WHERE token = ?'
        );
        $stmt->execute([$token]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable) {
        return null;
    }
    if (!is_string($raw) || $raw === '') {
        fullmakt_sql_cache_store($token, null);

        return null;
    }
    $payload = json_decode($raw, true);
    $doc = is_array($payload) ? $payload : null;
    fullmakt_sql_cache_store($token, $doc);

    return $doc;
}

function fullmakt_read_json(string $path): ?array
{
    if (!is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function fullmakt_write_json(string $path, array $data): void
{
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSON-fel');
    }
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte skriva fil');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Kunde inte spara fil');
    }
}

/**
 * Alla dokument med given status, en fråga. Per-request-cache, nollställs vid skrivning.
 *
 * @return array<string, array<string, mixed>>|null
 */
/** Bara token, ingen payload. PDF-öppning ska inte avkoda varje dokument. */
function fullmakt_sql_tokens_by_status(string $status): ?array
{
    if (!fullmakt_sql_active()) {
        return null;
    }
    $status = preg_replace('/[^a-z]/', '', strtolower($status)) ?? '';
    if ($status === '') {
        return null;
    }
    $cached = $GLOBALS['_JEAB_FULLMAKT_SQL_TOKENS'][$status] ?? null;
    if (is_array($cached)) {
        return $cached;
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_migrate_ident')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT token FROM ' . jeab_migrate_ident($pdo, 'fullmakt_docs') . ' WHERE status = ?'
        );
        $stmt->execute([$status]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        return null;
    }
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $token) {
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $token) ?? '';
        if ($token !== '') {
            $out[] = $token;
        }
    }
    if (!isset($GLOBALS['_JEAB_FULLMAKT_SQL_TOKENS']) || !is_array($GLOBALS['_JEAB_FULLMAKT_SQL_TOKENS'])) {
        $GLOBALS['_JEAB_FULLMAKT_SQL_TOKENS'] = [];
    }
    $GLOBALS['_JEAB_FULLMAKT_SQL_TOKENS'][$status] = $out;

    return $out;
}

function fullmakt_sql_docs_by_status(string $status): ?array
{
    if (!fullmakt_sql_active()) {
        return null;
    }
    $status = preg_replace('/[^a-z]/', '', strtolower($status)) ?? '';
    if ($status === '') {
        return null;
    }
    $cached = $GLOBALS['_JEAB_FULLMAKT_SQL_BY_STATUS'][$status] ?? null;
    if (is_array($cached)) {
        return $cached;
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_migrate_ident')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT token, payload FROM ' . jeab_migrate_ident($pdo, 'fullmakt_docs') . ' WHERE status = ?'
        );
        $stmt->execute([$status]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($row['token'] ?? '')) ?? '';
        if ($token === '') {
            continue;
        }
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }
        $out[$token] = $payload;
        if (!isset($GLOBALS['_JEAB_FULLMAKT_SQL_DOC']) || !is_array($GLOBALS['_JEAB_FULLMAKT_SQL_DOC'])) {
            $GLOBALS['_JEAB_FULLMAKT_SQL_DOC'] = [];
        }
        $GLOBALS['_JEAB_FULLMAKT_SQL_DOC'][$token] = $payload;
    }
    if (!isset($GLOBALS['_JEAB_FULLMAKT_SQL_BY_STATUS']) || !is_array($GLOBALS['_JEAB_FULLMAKT_SQL_BY_STATUS'])) {
        $GLOBALS['_JEAB_FULLMAKT_SQL_BY_STATUS'] = [];
    }
    $GLOBALS['_JEAB_FULLMAKT_SQL_BY_STATUS'][$status] = $out;

    return $out;
}

function fullmakt_create_doc(array $fields, ?string $pdfBytes = null, ?string $previewBytes = null): array
{
    $token = bin2hex(random_bytes(16));
    $doc = $fields;
    $doc['token'] = $token;
    $doc['status'] = 'pending';
    $doc['created_at'] = fullmakt_now()->format(DateTimeInterface::ATOM);

    if ($pdfBytes !== null && $pdfBytes !== '') {
        $pdfPath = fullmakt_original_pdf_path($token);
        if (file_put_contents($pdfPath, $pdfBytes, LOCK_EX) === false) {
            throw new RuntimeException('Kunde inte spara käll-PDF');
        }
        @chmod($pdfPath, 0640);
        $doc['source_pdf'] = $token . '.pdf';
    }
    if ($previewBytes !== null && $previewBytes !== '') {
        $pngPath = fullmakt_preview_png_path($token);
        if (file_put_contents($pngPath, $previewBytes, LOCK_EX) === false) {
            throw new RuntimeException('Kunde inte spara förhandsvisning');
        }
        @chmod($pngPath, 0640);
        $doc['preview_png'] = $token . '.png';
    }

    if (fullmakt_sql_active()) {
        fullmakt_sql_put($doc);
        return $doc;
    }
    fullmakt_write_json(fullmakt_pending_path($token), $doc);
    return $doc;
}

function fullmakt_save_pending(array $doc): void
{
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($doc['token'] ?? '')) ?? '';
    if ($token === '' || ($doc['status'] ?? '') === 'signed') {
        throw new RuntimeException('Dokumentet kan inte uppdateras');
    }
    $path = fullmakt_pending_path($token);
    if (fullmakt_sql_active()) {
        if (fullmakt_sql_get($token) === null && !is_file($path)) {
            throw new RuntimeException('Dokumentet hittades inte');
        }
        $doc['token'] = $token;
        fullmakt_sql_put($doc);
        return;
    }
    if (!is_file($path)) {
        throw new RuntimeException('Dokumentet hittades inte');
    }
    $doc['token'] = $token;
    fullmakt_write_json($path, $doc);
}

/**
 * Signeringssidan laddas utan JEAB-appen. Vid själva signeringen behövs
 * appens orderlager (MySQL på Loopia) för att hitta ordern bakom token.
 * Utan det läses en gammal purchases.json och order_id blir tomt i
 * fullmakt.signed. Appen laddas därför här, en gång, efter att PDF och
 * json är sparade. Går det inte faller vi tillbaka på bara webhook-filen.
 */
function fullmakt_load_jeab_for_signed(): void
{
    if (function_exists('jeab_config') || defined('JEAB_ROOT')) {
        return;
    }
    $boot = dirname(FULLMAKT_ROOT) . '/src/bootstrap.php';
    if (!is_file($boot)) {
        return;
    }
    try {
        require_once $boot;
    } catch (Throwable $e) {
        if (function_exists('fullmakt_log')) {
            fullmakt_log('error.log', 'jeab ' . $e->getMessage());
        }
    }
}

function fullmakt_load_bokmalen_ellevio_webhook(): void
{
    fullmakt_load_jeab_for_signed();
    if (function_exists('jeab_bokmalen_ellevio_fullmakt_notify')) {
        return;
    }
    $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(FULLMAKT_ROOT);
    if (!defined('JEAB_ROOT')) {
        define('JEAB_ROOT', $root);
    }
    $file = $root . '/src/bokmalen_ellevio_fullmakt_webhook.php';
    if (is_file($file)) {
        require_once $file;
    }
}

function fullmakt_bokmalen_ellevio_after_signed(array $signed): void
{
    if (array_key_exists('_JEAB_FULLMAKT_AFTER_SIGNED_HITS', $GLOBALS)) {
        $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS']++;
    }
    try {
        fullmakt_load_bokmalen_ellevio_webhook();
    } catch (Throwable $ignore) {
        return;
    }
    // Anläggnings-id skrivs på ordern innan fullmakt.signed, i samma signering.
    if (function_exists('jeab_bokmalen_ellevio_fullmakt_apply_signed_order')) {
        try {
            jeab_bokmalen_ellevio_fullmakt_apply_signed_order($signed);
        } catch (Throwable $ignore) {
        }
    }
    if (function_exists('jeab_bokmalen_ellevio_fullmakt_notify')) {
        try {
            jeab_bokmalen_ellevio_fullmakt_notify($signed);
        } catch (Throwable $ignore) {
        }
    }
}

function fullmakt_load_doc(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    // /fullmakt/s/ når hit innan JEAB. Utan jeab_cutover_sql läses bara JSON.
    if (!function_exists('jeab_cutover_sql')) {
        if (!function_exists('fullmakt_load_jeab')) {
            require_once __DIR__ . '/parties.php';
        }
        fullmakt_load_jeab();
    }
    $sql = fullmakt_sql_get($token);
    if (is_array($sql)) {
        return $sql;
    }
    // SQL på: pending-JSON visas inte. Signeringen hittar inte raden.
    // Signerad JSON på disk får läsas tills raden finns i SQL.
    if (fullmakt_sql_active()) {
        $signedOnly = fullmakt_read_json(fullmakt_signed_json_path($token));

        return is_array($signedOnly) ? $signedOnly : null;
    }
    $pending = fullmakt_read_json(fullmakt_pending_path($token));
    if ($pending !== null) {
        return $pending;
    }
    $signed = fullmakt_read_json(fullmakt_signed_json_path($token));
    if ($signed !== null) {
        return $signed;
    }
    return null;
}

/**
 * Dokument som bara finns som JSON-fil och saknas i fullmakt_docs.
 * Bara en räknare. Inga token och ingen dokumenttext.
 * Flaggan av (SQL-vägen inte aktiv) ger 0, samma läge som innan räknaren fanns.
 *
 * @return array{ok:bool,dry:bool,json_only:int}
 */
function fullmakt_sql_verify(): array
{
    return [
        'ok' => true,
        'dry' => true,
        'json_only' => fullmakt_json_only_count(),
    ];
}

function fullmakt_json_only_count(): int
{
    if (!fullmakt_sql_active()) {
        return 0;
    }
    $sql = fullmakt_sql_token_set();
    if ($sql === null) {
        return 0;
    }
    $count = 0;
    foreach (fullmakt_json_doc_tokens() as $token) {
        if (!isset($sql[$token])) {
            $count++;
        }
    }

    return $count;
}

/** @return list<string> */
function fullmakt_json_doc_tokens(): array
{
    $dirs = [];
    if (defined('FULLMAKT_PENDING')) {
        $dirs[] = FULLMAKT_PENDING;
    }
    if (defined('FULLMAKT_SIGNED')) {
        $dirs[] = FULLMAKT_SIGNED;
    }
    if (function_exists('fullmakt_legacy_dirs')) {
        foreach (fullmakt_legacy_dirs() as $root) {
            $dirs[] = $root . '/pending';
            $dirs[] = $root . '/signed';
        }
    }
    $tokens = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if (!is_string($name) || !preg_match('/^([A-Za-z0-9_-]+)\.json$/', $name, $match)) {
                continue;
            }
            $tokens[$match[1]] = true;
        }
    }

    return array_keys($tokens);
}

/** @return array<string, true>|null null när tabellen inte gick att läsa */
function fullmakt_sql_token_set(): ?array
{
    if (!function_exists('jeab_data_pdo') || !function_exists('jeab_migrate_ident')) {
        return null;
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        $stmt = $pdo->query(
            'SELECT ' . jeab_migrate_ident($pdo, 'token') . ' FROM ' . jeab_migrate_ident($pdo, 'fullmakt_docs')
        );
    } catch (Throwable) {
        return null;
    }
    if (!$stmt instanceof PDOStatement) {
        return null;
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $token) {
        $token = trim((string) $token);
        if ($token !== '') {
            $out[$token] = true;
        }
    }

    return $out;
}

function fullmakt_mark_signed(string $token, array $pending, string $pdfBytes, array $extra = []): array
{
    if (fullmakt_sql_active()) {
        return fullmakt_mark_signed_sql($token, $pending, $pdfBytes, $extra);
    }
    $pendingPath = fullmakt_pending_path($token);
    $fh = fopen($pendingPath, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Dokumentet hittades inte');
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Kunde inte låsa dokumentet');
        }
        $fresh = fullmakt_read_json($pendingPath);
        if ($fresh === null || (($fresh['status'] ?? '') === 'signed')) {
            throw new RuntimeException('Dokumentet är redan signerat');
        }
        $now = fullmakt_now();
        $signed = fullmakt_assert_ellevio_anlaggnings_id(array_merge($fresh, $extra));
        $signed['status'] = 'signed';
        $signed['signed_at'] = $now->format(DateTimeInterface::ATOM);
        $signed['ip'] = fullmakt_client_ip();
        $signed['pdf'] = $token . '.pdf';

        $pdfPath = fullmakt_signed_pdf_path($token);
        $pdfTmp = $pdfPath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($pdfTmp, $pdfBytes, LOCK_EX) === false) {
            throw new RuntimeException('Kunde inte spara PDF');
        }
        if (!rename($pdfTmp, $pdfPath)) {
            @unlink($pdfTmp);
            throw new RuntimeException('Kunde inte spara PDF');
        }
        @chmod($pdfPath, 0640);
        fullmakt_write_json(fullmakt_signed_json_path($token), $signed);
        flock($fh, LOCK_UN);
        fclose($fh);
        $fh = null;
        @unlink($pendingPath);
        // PDF och json är redan sparade. Webhook-fel får inte rulla tillbaka signeringen.
        fullmakt_bokmalen_ellevio_after_signed($signed);
        $signed['signed_winner'] = true;
        return $signed;
    } catch (Throwable $e) {
        if ($fh) {
            try {
                flock($fh, LOCK_UN);
                fclose($fh);
            } catch (Throwable $ignore) {
            }
        }
        throw $e;
    }
}

/**
 * SQL är enda sanningen när SQL-vägen är aktiv. Pending-JSON läses inte
 * före signering. Saknas token i fullmakt_docs blir det fel (hittades inte).
 * Lås: BEGIN och SELECT payload, status ... FOR UPDATE. sqlite: BEGIN IMMEDIATE
 * utan FOR UPDATE. UPDATE kräver rowCount()==1, annars no-op (redan signerad).
 *
 * PDF skrivs till en tmp-fil i samma katalog före UPDATE och byter namn till
 * slutfilen efter commit. Vid rollback tas tmp-filen bort. Saknas PDF:en för
 * ett redan signerat dokument skrivs den på nytt, utan att röra en fil som
 * redan finns och utan en ny after_signed. Tmp och rename gäller bara PDF:en.
 *
 * R2: inTransaction() redan sann före start ger LogicException.
 * after_signed körs bara efter vår egen commit. PDO har ingen krok som väntar
 * in en yttre commit, så att kasta är det enkla säkra valet: ingen webhook
 * om den yttre transaktionen sedan rullas tillbaka.
 *
 * after_signed körs en gång, efter commit, av vinnaren, även om PDF-publiceringen faller
 * (PDF:en skrivs då om vid retry). signed_notified=false i payload markerar väntande
 * notis; retry tar den med compare-and-set. Rader utan nyckeln notifieras aldrig om.
 * Vid fel körs rollBack i finally.
 */
function fullmakt_mark_signed_sql(string $token, array $pending, string $pdfBytes, array $extra = []): array
{
    unset($pending);
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token) ?? '';
    if ($token === '') {
        throw new RuntimeException('Dokumentet hittades inte');
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO || !function_exists('jeab_migrate_ident')) {
        throw new RuntimeException('Dokumentet hittades inte');
    }
    fullmakt_sql_cache_forget($token);
    if (function_exists('jeab_pdo_in_tx') ? jeab_pdo_in_tx($pdo) : $pdo->inTransaction()) {
        throw new LogicException('Signering kan inte dela en öppen transaktion');
    }
    if (function_exists('jeab_migrate_find') && function_exists('jeab_migrate_ensure')) {
        $module = jeab_migrate_find('fullmakt');
        if (is_array($module)) {
            jeab_migrate_ensure($pdo, $module);
        }
    }
    $own = false;
    $winner = null;
    $done = null;
    $error = null;
    $pdfTmp = null;
    try {
        $own = fullmakt_sql_tx_begin($pdo);
        $sqlite = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $sql = 'SELECT payload, status FROM ' . jeab_migrate_ident($pdo, 'fullmakt_docs') . ' WHERE token = ?';
        if (!$sqlite) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Dokumentet hittades inte');
        }
        $status = (string) ($row['status'] ?? '');
        if ($status === 'signed') {
            fullmakt_sql_tx_commit($pdo, $own);
            $own = false;
            $decoded = json_decode((string) ($row['payload'] ?? ''), true);
            $done = is_array($decoded) ? $decoded : ['token' => $token, 'status' => 'signed'];
        } else {
            $fresh = json_decode((string) ($row['payload'] ?? ''), true);
            if (!is_array($fresh)) {
                throw new RuntimeException('Dokumentet hittades inte');
            }
            $now = fullmakt_now();
            $signed = fullmakt_assert_ellevio_anlaggnings_id(array_merge($fresh, $extra));
            $signed['token'] = $token;
            $signed['status'] = 'signed';
            $signed['signed_at'] = $now->format(DateTimeInterface::ATOM);
            $signed['ip'] = fullmakt_client_ip();
            $signed['pdf'] = $token . '.pdf';
            // false = after_signed väntar. Rader utan nyckeln (signerade före ändringen) notifieras aldrig om.
            $signed['signed_notified'] = false;
            $payload = json_encode($signed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($payload)) {
                throw new RuntimeException('JSON-fel');
            }
            $created = function_exists('jeab_migrate_datetime') ? jeab_migrate_datetime($signed['created_at'] ?? null) : null;
            $pdfTmp = fullmakt_stage_signed_pdf($token, $pdfBytes);
            $upd = $pdo->prepare(
                'UPDATE ' . jeab_migrate_ident($pdo, 'fullmakt_docs')
                . " SET status = ?, created_at = ?, payload = ? WHERE token = ? AND status <> 'signed'"
            );
            $upd->execute(['signed', $created, $payload, $token]);
            if ($upd->rowCount() !== 1) {
                fullmakt_discard_signed_pdf_tmp($pdfTmp);
                $pdfTmp = null;
                $again = $pdo->prepare(
                    'SELECT payload, status FROM ' . jeab_migrate_ident($pdo, 'fullmakt_docs') . ' WHERE token = ?'
                );
                $again->execute([$token]);
                $lost = $again->fetch(PDO::FETCH_ASSOC);
                if (!is_array($lost) || (string) ($lost['status'] ?? '') !== 'signed') {
                    throw new RuntimeException('Dokumentet kan inte signeras');
                }
                fullmakt_sql_tx_commit($pdo, $own);
                $own = false;
                $decoded = json_decode((string) ($lost['payload'] ?? ''), true);
                $done = is_array($decoded) ? $decoded : ['token' => $token, 'status' => 'signed'];
            } else {
                fullmakt_sql_tx_commit($pdo, $own);
                $own = false;
                $staged = $pdfTmp;
                $pdfTmp = null;
                $winner = $signed;
                try {
                    fullmakt_signed_pdf_with_lock($token, static function () use ($staged, $token): void {
                        fullmakt_publish_signed_pdf($staged, $token, false);
                    });
                } catch (Throwable $publishError) {
                    // Signeringen är committad. after_signed ska ändå köras; PDF:en skrivs om vid retry.
                    fullmakt_discard_signed_pdf_tmp($staged);
                    error_log('fullmakt: PDF-publicering efter commit misslyckades (' . get_class($publishError) . ')');
                }
                $done = $signed;
            }
        }
    } catch (Throwable $e) {
        $error = $e;
    } finally {
        if ($own) {
            try {
                fullmakt_sql_tx_end($pdo, false);
            } catch (Throwable) {
            }
        }
        if (is_string($pdfTmp) && $pdfTmp !== '') {
            fullmakt_discard_signed_pdf_tmp($pdfTmp);
            $pdfTmp = null;
        }
    }
    if ($error instanceof Throwable) {
        throw $error;
    }
    if (is_array($winner)) {
        if (fullmakt_sql_claim_notify($pdo, $token)) {
            fullmakt_bokmalen_ellevio_after_signed($winner);
        }
        $winner['signed_notified'] = true;
        $winner['signed_winner'] = true;

        return $winner;
    }
    if (is_array($done)) {
        if (($done['status'] ?? '') === 'signed' && !is_file(fullmakt_signed_pdf_path($token))) {
            try {
                fullmakt_write_signed_pdf($token, $pdfBytes);
            } catch (Throwable $pdfError) {
                error_log('fullmakt: PDF kunde inte skrivas om vid retry (' . get_class($pdfError) . ')');
            }
        }
        // Retry: after_signed som inte hann köras (signed_notified === false) körs nu, en gång.
        if (($done['status'] ?? '') === 'signed' && array_key_exists('signed_notified', $done) && $done['signed_notified'] === false
            && fullmakt_sql_claim_notify($pdo, $token)) {
            fullmakt_bokmalen_ellevio_after_signed($done);
            $done['signed_notified'] = true;
        }

        return $done;
    }
    throw new RuntimeException('Dokumentet kan inte signeras');
}

/**
 * Tar notisen för en signerad rad med compare-and-set på payload.
 * Sant bara om signed_notified var exakt false och vår UPDATE ändrade raden.
 */
function fullmakt_sql_claim_notify(PDO $pdo, string $token): bool
{
    try {
        $table = jeab_migrate_ident($pdo, 'fullmakt_docs');
        $sel = $pdo->prepare('SELECT payload FROM ' . $table . " WHERE token = ? AND status = 'signed'");
        $sel->execute([$token]);
        $old = $sel->fetchColumn();
        if (!is_string($old)) {
            return false;
        }
        $doc = json_decode($old, true);
        if (!is_array($doc) || !array_key_exists('signed_notified', $doc) || $doc['signed_notified'] !== false) {
            return false;
        }
        $doc['signed_notified'] = true;
        $new = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($new)) {
            return false;
        }
        $upd = $pdo->prepare('UPDATE ' . $table . ' SET payload = ? WHERE token = ? AND payload = ?');
        $upd->execute([$new, $token, $old]);

        return $upd->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('fullmakt: notis kunde inte markeras (' . get_class($e) . ')');

        return false;
    }
}

function fullmakt_sql_tx_begin(PDO $pdo): bool
{
    if (function_exists('jeab_pdo_in_tx') ? jeab_pdo_in_tx($pdo) : $pdo->inTransaction()) {
        return false;
    }
    if (function_exists('jeab_pdo_begin_immediate')) {
        jeab_pdo_begin_immediate($pdo);
    } else {
        $pdo->beginTransaction();
    }

    return true;
}

function fullmakt_sql_tx_commit(PDO $pdo, bool $own): void
{
    if (!$own) {
        return;
    }
    fullmakt_sql_tx_end($pdo, true);
}

/** PHP 8.3: en egen BEGIN IMMEDIATE syns inte i inTransaction(), så commit/rollback går via jeab_pdo_end. */
function fullmakt_sql_tx_end(PDO $pdo, bool $commit): void
{
    if (function_exists('jeab_pdo_end')) {
        jeab_pdo_end($pdo, $commit);

        return;
    }
    if ($pdo->inTransaction()) {
        $commit ? $pdo->commit() : $pdo->rollBack();
    }
}

/**
 * Exklusivt lås på PDF-sökvägen. Samma process får nästla.
 * En förlorare som väntar ser vinnarens fil och skriver inte över den.
 *
 * @param callable(): mixed $fn
 */
function fullmakt_signed_pdf_with_lock(string $token, callable $fn): mixed
{
    $key = preg_replace('/[^A-Za-z0-9_-]/', '', $token) ?? '';
    if ($key === '') {
        throw new RuntimeException('Kunde inte spara PDF');
    }
    if (!isset($GLOBALS['_JEAB_FULLMAKT_PDF_LOCK_DEPTH']) || !is_array($GLOBALS['_JEAB_FULLMAKT_PDF_LOCK_DEPTH'])) {
        $GLOBALS['_JEAB_FULLMAKT_PDF_LOCK_DEPTH'] = [];
    }
    $depth = (int) ($GLOBALS['_JEAB_FULLMAKT_PDF_LOCK_DEPTH'][$key] ?? 0);
    if ($depth > 0) {
        $GLOBALS['_JEAB_FULLMAKT_PDF_LOCK_DEPTH'][$key] = $depth + 1;
        try {
            return $fn();
        } finally {
            $GLOBALS['_JEAB_FULLMAKT_PDF_LOCK_DEPTH'][$key] = $depth;
        }
    }
    $pdfPath = fullmakt_signed_pdf_path($key);
    $dir = dirname($pdfPath);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte spara PDF');
    }
    $fh = fopen($pdfPath . '.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        if (is_resource($fh)) {
            fclose($fh);
        }
        throw new RuntimeException('Kunde inte spara PDF');
    }
    $GLOBALS['_JEAB_FULLMAKT_PDF_LOCK_DEPTH'][$key] = 1;
    try {
        return $fn();
    } finally {
        $GLOBALS['_JEAB_FULLMAKT_PDF_LOCK_DEPTH'][$key] = 0;
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Idempotent. Skriver bara om slutfilen saknas. Används när SQL redan är
 * signed och ett nytt försök ska återskapa PDF:en.
 * Kontrollen av att filen saknas sker under samma lås som vinnarens publicering.
 */
function fullmakt_write_signed_pdf(string $token, string $pdfBytes): void
{
    fullmakt_signed_pdf_with_lock($token, static function () use ($token, $pdfBytes): void {
        if (is_file(fullmakt_signed_pdf_path($token))) {
            return;
        }
        $tmp = fullmakt_stage_signed_pdf($token, $pdfBytes);
        try {
            fullmakt_publish_signed_pdf($tmp, $token, true);
        } catch (Throwable $e) {
            fullmakt_discard_signed_pdf_tmp($tmp);
            throw $e;
        }
    });
}

/** Tmp-fil i samma katalog som slut-PDF:en. Returnerar sökvägen. */
function fullmakt_stage_signed_pdf(string $token, string $pdfBytes): string
{
    $pdfPath = fullmakt_signed_pdf_path($token);
    $dir = dirname($pdfPath);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte spara PDF');
    }
    $tmp = $pdfPath . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $pdfBytes, LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Kunde inte spara PDF');
    }

    return $tmp;
}

function fullmakt_publish_signed_pdf(string $tmp, string $token, bool $onlyIfMissing): void
{
    $pdfPath = fullmakt_signed_pdf_path($token);
    if ($onlyIfMissing && is_file($pdfPath)) {
        fullmakt_discard_signed_pdf_tmp($tmp);

        return;
    }
    if (dirname($tmp) !== dirname($pdfPath) || !rename($tmp, $pdfPath)) {
        fullmakt_discard_signed_pdf_tmp($tmp);
        throw new RuntimeException('Kunde inte spara PDF');
    }
    @chmod($pdfPath, 0640);
}

function fullmakt_discard_signed_pdf_tmp(?string $tmp): void
{
    if (!is_string($tmp) || $tmp === '' || !is_file($tmp)) {
        return;
    }
    if (!preg_match('/\.pdf\.tmp\.[a-f0-9]+$/', str_replace('\\', '/', $tmp))) {
        return;
    }
    @unlink($tmp);
}
