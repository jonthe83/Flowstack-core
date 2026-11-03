<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Signerade fullmakter läses från samma arkiv som fullmakten, utan att
 * flytta eller skapa kataloger. Kanonisk signed/ först (FULLMAKT_DATA:
 * miljö, annars privat/fullmakt, annars data/.private/fullmakt), sedan
 * äldre signed/ under fullmakt/data och data/.private/fullmakt.
 * Filerna heter token på disk. Webbläsaren får bara ett kort id som
 * inte är token, sökväg eller signeringslänk.
 */
function jeab_fullmakt_web_root(): string
{
    if (defined('FULLMAKT_ROOT')) {
        return rtrim(str_replace('\\', '/', dirname((string) FULLMAKT_ROOT)), '/');
    }
    return rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
}

function jeab_fullmakt_data_root(): string
{
    if (defined('FULLMAKT_DATA')) {
        return rtrim(str_replace('\\', '/', (string) FULLMAKT_DATA), '/');
    }
    $env = getenv('FULLMAKT_DATA');
    if (is_string($env) && $env !== '') {
        return rtrim(str_replace('\\', '/', $env), '/');
    }
    $web = jeab_fullmakt_web_root();
    $outsideParent = dirname($web) . '/privat';
    $outside = $outsideParent . '/fullmakt';
    if (is_dir($outside) || (is_dir($outsideParent) && is_writable($outsideParent))) {
        return str_replace('\\', '/', $outside);
    }
    return $web . '/data/.private/fullmakt';
}

function jeab_fullmakt_signed_dir(): string
{
    if (defined('FULLMAKT_SIGNED')) {
        return rtrim(str_replace('\\', '/', (string) FULLMAKT_SIGNED), '/');
    }
    return jeab_fullmakt_data_root() . '/signed';
}

/**
 * Kanonisk signed/ först, sedan legacy. Dubbletter bort. Ingen mkdir.
 *
 * @return list<string>
 */
function jeab_fullmakt_signed_dirs(): array
{
    $canonicalSigned = jeab_fullmakt_signed_dir();
    $canonicalRoot = str_ends_with($canonicalSigned, '/signed')
        ? substr($canonicalSigned, 0, -strlen('/signed'))
        : jeab_fullmakt_data_root();
    $web = jeab_fullmakt_web_root();
    $legacyRoots = [
        rtrim(str_replace('\\', '/', $web . '/fullmakt/data'), '/'),
        rtrim(str_replace('\\', '/', $web . '/data/.private/fullmakt'), '/'),
    ];
    $dirs = [$canonicalSigned];
    foreach ($legacyRoots as $root) {
        if ($root === '' || $root === $canonicalRoot) {
            continue;
        }
        $dirs[] = $root . '/signed';
    }
    $out = [];
    foreach ($dirs as $dir) {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || in_array($dir, $out, true)) {
            continue;
        }
        $out[] = $dir;
    }
    return $out;
}

/**
 * Som fullmakt_slot för signed/{token}.{suffix}, men läser bara.
 * Kanonisk fil om den är en riktig fil, annars första legacy-filen.
 */
function jeab_fullmakt_signed_resolve(string $token, string $suffix): ?string
{
    if (preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
        return null;
    }
    if ($suffix !== 'json' && $suffix !== 'pdf') {
        return null;
    }
    $dirs = jeab_fullmakt_signed_dirs();
    if ($dirs === []) {
        return null;
    }
    $name = $token . '.' . $suffix;
    $canonical = $dirs[0] . '/' . $name;
    if (is_file($canonical)) {
        return $canonical;
    }
    foreach (array_slice($dirs, 1) as $dir) {
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * @return list<string>
 */
/**
 * Signerade dokument i SQL, en fråga. null = SQL-vägen är av eller gick inte att läsa.
 *
 * @return array<string, array<string, mixed>>|null
 */
function jeab_fullmakt_signed_sql_docs(): ?array
{
    if (!function_exists('jeab_cutover_sql') || !jeab_cutover_sql('fullmakt')) {
        return null;
    }
    if (!function_exists('fullmakt_sql_docs_by_status')) {
        $root = dirname(__DIR__);
        $boot = $root . '/fullmakt/lib/bootstrap.php';
        $store = $root . '/fullmakt/lib/store.php';
        if (!is_file($boot) || !is_file($store)) {
            return null;
        }
        require_once $boot;
        require_once $store;
    }
    if (!function_exists('fullmakt_sql_docs_by_status')) {
        return null;
    }

    return fullmakt_sql_docs_by_status('signed');
}

function jeab_fullmakt_signed_tokens(): array
{
    $tokens = [];
    $seen = [];
    $sqlDocs = jeab_fullmakt_signed_sql_docs();
    if (is_array($sqlDocs)) {
        foreach (array_keys($sqlDocs) as $token) {
            $token = (string) $token;
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $tokens[] = $token;
        }
    }
    foreach (jeab_fullmakt_signed_dirs() as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $base = basename((string) $file);
            if (preg_match('/^[A-Za-z0-9_-]+\.json$/', $base) !== 1) {
                continue;
            }
            $token = substr($base, 0, -5);
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $tokens[] = $token;
        }
    }
    return $tokens;
}

function jeab_fullmakt_signed_public_id(string $token): string
{
    return substr(hash('sha256', 'jeab-signed-v1:' . $token), 0, 24);
}

function jeab_fullmakt_signed_address(array $doc): string
{
    $parter = is_array($doc['parter'] ?? null) ? $doc['parter'] : [];
    $huvud = is_array($parter['huvudsokande'] ?? null) ? $parter['huvudsokande'] : [];
    $street = trim((string) ($doc['adress'] ?? ''));
    if ($street === '') {
        $street = trim((string) ($huvud['adress'] ?? ''));
    }
    if ($street === '') {
        $street = trim((string) ($doc['anlaggningsadress'] ?? $huvud['anlaggningsadress'] ?? ''));
    }
    $zip = trim((string) ($doc['postnummer'] ?? $huvud['postnummer'] ?? ''));
    $ort = trim((string) ($doc['ort'] ?? $huvud['ort'] ?? ''));
    $city = trim($zip . ' ' . $ort);
    if ($street !== '' && $city !== '') {
        return $street . ', ' . $city;
    }
    return $street !== '' ? $street : $city;
}

function jeab_fullmakt_signed_name(array $doc): string
{
    $name = trim((string) ($doc['namn'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $parter = is_array($doc['parter'] ?? null) ? $doc['parter'] : [];
    $huvud = is_array($parter['huvudsokande'] ?? null) ? $parter['huvudsokande'] : [];
    return trim((string) ($huvud['namn'] ?? ''));
}

function jeab_fullmakt_signed_time_label(array $doc): string
{
    $raw = trim((string) ($doc['signed_at'] ?? ''));
    if ($raw === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($raw);
    } catch (Exception) {
        return '';
    }
    return $dt->setTimezone(new DateTimeZone('Europe/Stockholm'))->format('Y-m-d H:i');
}

/**
 * @return list<array{name:string,address:string,time:string,id:string}>
 */
function jeab_fullmakt_signed_rows(): array
{
    $rows = [];
    $sqlDocs = jeab_fullmakt_signed_sql_docs();
    foreach (jeab_fullmakt_signed_tokens() as $token) {
        $pdfPath = jeab_fullmakt_signed_resolve($token, 'pdf');
        if ($pdfPath === null) {
            continue;
        }
        $doc = is_array($sqlDocs) && isset($sqlDocs[$token]) && is_array($sqlDocs[$token]) ? $sqlDocs[$token] : null;
        if (!is_array($doc)) {
            $jsonPath = jeab_fullmakt_signed_resolve($token, 'json');
            if ($jsonPath === null) {
                continue;
            }
            $raw = @file_get_contents($jsonPath);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $doc = json_decode($raw, true);
            if (!is_array($doc)) {
                continue;
            }
        }
        $rows[] = [
            'name' => jeab_fullmakt_signed_name($doc),
            'address' => jeab_fullmakt_signed_address($doc),
            'time' => jeab_fullmakt_signed_time_label($doc),
            'id' => jeab_fullmakt_signed_public_id($token),
            'sort' => trim((string) ($doc['signed_at'] ?? '')),
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) $b['sort'], (string) $a['sort']);
    });
    foreach ($rows as $i => $row) {
        unset($rows[$i]['sort']);
    }
    return array_values($rows);
}

function jeab_fullmakt_signed_list_html(array $rows): string
{
    ob_start();
    ?>
<div class="links-page">
    <h1>E-signering</h1>
    <p class="ordrar-help"><a href="<?= e(jeab_url('index.php?p=links')) ?>">Tillbaka till Övrigt</a></p>
    <?php if ($rows === []): ?>
        <p class="ordrar-help">Inga signerade fullmakter.</p>
    <?php else: ?>
        <ul class="list links-list">
            <?php foreach ($rows as $row): ?>
                <?php
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    $name = 'Namn saknas';
                }
                $address = trim((string) ($row['address'] ?? ''));
                $time = trim((string) ($row['time'] ?? ''));
                $id = trim((string) ($row['id'] ?? ''));
                if (preg_match('/^[a-f0-9]{24}$/', $id) !== 1) {
                    continue;
                }
                $href = jeab_url('index.php?p=fullmakter&vis=' . rawurlencode($id));
                ?>
                <li>
                    <a class="link-row" href="<?= e($href) ?>">
                        <span><?= e($name) ?><?php if ($address !== ''): ?> · <?= e($address) ?><?php endif; ?><?php if ($time !== ''): ?> · <?= e($time) ?><?php endif; ?></span>
                        <i aria-hidden="true">PDF</i>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Token att matcha mot ett publikt id. Läser inte payload.
 *
 * @return list<string>
 */
function jeab_fullmakt_signed_find_tokens(): array
{
    $tokens = [];
    $seen = [];
    $sql = null;
    // Ladda inte fullmakt/lib/bootstrap.php här. Den fryser FULLMAKT_DATA
    // och då slutar en senare katalogväxling att gälla.
    if (function_exists('fullmakt_sql_tokens_by_status')) {
        $sql = fullmakt_sql_tokens_by_status('signed');
    } elseif (function_exists('jeab_cutover_sql') && jeab_cutover_sql('fullmakt')
        && function_exists('jeab_data_pdo') && function_exists('jeab_migrate_ident')) {
        $pdo = jeab_data_pdo();
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT token FROM ' . jeab_migrate_ident($pdo, 'fullmakt_docs') . ' WHERE status = ?'
                );
                $stmt->execute(['signed']);
                $col = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $sql = is_array($col) ? $col : [];
            } catch (Throwable) {
                $sql = [];
            }
        }
    }
    if (is_array($sql)) {
        foreach ($sql as $token) {
            $token = (string) $token;
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $tokens[] = $token;
        }
    }
    foreach (jeab_fullmakt_signed_dirs() as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $base = basename((string) $file);
            if (preg_match('/^[A-Za-z0-9_-]+\.json$/', $base) !== 1) {
                continue;
            }
            $token = substr($base, 0, -5);
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $tokens[] = $token;
        }
    }

    return $tokens;
}

function jeab_fullmakt_signed_find_pdf(string $publicId): ?string
{
    if (preg_match('/^[a-f0-9]{24}$/', $publicId) !== 1) {
        return null;
    }
    $started = microtime(true);
    $found = null;
    foreach (jeab_fullmakt_signed_find_tokens() as $token) {
        if (!hash_equals(jeab_fullmakt_signed_public_id($token), $publicId)) {
            continue;
        }
        $pdf = jeab_fullmakt_signed_resolve($token, 'pdf');
        if ($pdf !== null && is_file($pdf) && is_readable($pdf)) {
            $found = $pdf;
            break;
        }
    }
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('fullmakt_pdf', microtime(true) - $started);
    }

    return $found;
}

function jeab_fullmakt_signed_send(string $publicId): void
{
    $pdf = jeab_fullmakt_signed_find_pdf($publicId);
    if ($pdf === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo "Hittades inte.\n";
        exit;
    }
    $size = filesize($pdf);
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="fullmakt.pdf"');
    if (is_int($size) && $size >= 0) {
        header('Content-Length: ' . (string) $size);
    }
    readfile($pdf);
    exit;
}
