<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * json.inventory listar varje .json under data/ och data/.private.
 * json.trash_unused flyttar bara rader som registret klassar som inactive.
 * SQL är lagret. Inget skrivs tillbaka till JSON. Okända filer flyttas inte.
 * dry saknas betyder dry=1. Ingenting raderas.
 */

/**
 * @return list<array{rel: string, class: string, reason: string, evidence: string, prefix?: bool}>
 */
function jeab_json_registry(): array
{
    return [
        ['rel' => 'passwords.json', 'class' => 'active', 'reason' => 'lösenord vid inloggning', 'evidence' => 'src/auth.php:120'],
        ['rel' => '.passwords.json', 'class' => 'active', 'reason' => 'lösenord vid inloggning', 'evidence' => 'src/auth.php:124'],
        ['rel' => 'fortnox/tokens.json', 'class' => 'active', 'reason' => 'token vid Fortnox', 'evidence' => 'src/shield.php:452'],
        ['rel' => 'fortnox/tokens.rotated.json', 'class' => 'active', 'reason' => 'roterad Fortnox-token', 'evidence' => 'src/shield.php:457'],
        ['rel' => 'secrets/', 'class' => 'active', 'reason' => 'hemlighet läses vid anrop', 'evidence' => 'src/webshop.php:111', 'prefix' => true],
        ['rel' => 'suresms.json', 'class' => 'active', 'reason' => 'SMS-nyckel', 'evidence' => 'src/sms.php:29'],
        ['rel' => 'helpscout.json', 'class' => 'active', 'reason' => 'Help Scout-nyckel', 'evidence' => 'src/sms.php:39'],
        ['rel' => 'offert-defaults.json', 'class' => 'active', 'reason' => 'offertens standardvärden', 'evidence' => 'src/offert.php:621'],
        ['rel' => 'quote-queue.json', 'class' => 'active', 'reason' => 'offertkö på fil', 'evidence' => 'src/inkorg_auto.php:5813'],
        ['rel' => 'inkorg/classify-pending.json', 'class' => 'active', 'reason' => 'klassningskö på fil', 'evidence' => 'src/inkorg_auto.php:3029'],
        ['rel' => 'inkorg/rules.json', 'class' => 'active', 'reason' => 'regler läses direkt', 'evidence' => 'src/inkorg.php:660'],
        ['rel' => 'inkorg/settings.json', 'class' => 'active', 'reason' => 'inkorgens egna inställningar', 'evidence' => 'src/inkorg.php:441'],
        ['rel' => 'inkorg/graph-daily.json', 'class' => 'active', 'reason' => 'tom SQL skickar om', 'evidence' => 'src/inkorg_graph.php:1793'],
        ['rel' => 'inkorg/graph-poll.json', 'class' => 'active', 'reason' => 'tom SQL skickar om', 'evidence' => 'src/inkorg_graph.php:1860'],
        ['rel' => 'sms-sent.json', 'class' => 'active', 'reason' => 'tom SQL skickar om', 'evidence' => 'src/sms.php:20'],
        ['rel' => 'fellogg-open-asks.json', 'class' => 'active', 'reason' => 'öppna felfrågor', 'evidence' => 'src/security.php:1153'],
        ['rel' => 'login-throttle.json', 'class' => 'active', 'reason' => 'spärrar inloggning', 'evidence' => 'src/auth.php:1844'],
        ['rel' => 'store.json', 'class' => 'active', 'reason' => 'bokningar rörs inte', 'evidence' => 'src/store.php:499'],
        ['rel' => 'code-people.json', 'class' => 'active', 'reason' => 'personallista', 'evidence' => 'src/people.php:588'],
        ['rel' => 'reminder-ort-cache.json', 'class' => 'active', 'reason' => 'cachad ort', 'evidence' => 'src/reminders.php:1253'],
        ['rel' => 'reminders-prune.json', 'class' => 'active', 'reason' => 'rensningsläge', 'evidence' => 'src/reminders.php:3015'],
        ['rel' => 'webshop-catchup.json', 'class' => 'active', 'reason' => 'webbshopens hämtning', 'evidence' => 'src/webshop_wix.php:526'],
        ['rel' => 'solkampanj/settings.json', 'class' => 'active', 'reason' => 'kampanjinställningar', 'evidence' => 'src/solkampanj.php:94'],
        ['rel' => 'solkampanj/orts.json', 'class' => 'active', 'reason' => 'kampanjorter', 'evidence' => 'src/solkampanj.php:940'],
        ['rel' => 'inkorg-trace.json', 'class' => 'active', 'reason' => 'spår läses alltid', 'evidence' => 'src/inkorg_trace.php:453'],
        ['rel' => 'ordrar-deleted.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/ordrar.php:8024'],
        ['rel' => 'calendar-log.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/bookings.php:3122'],
        ['rel' => 'purchases.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/purchase.php:325'],
        ['rel' => 'kontakter.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/kontakt_store.php:50'],
        ['rel' => 'notes.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/notes_store.php:435'],
        ['rel' => 'webshop-orders.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/webshop.php:38'],
        ['rel' => 'who-picks.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/who_identity.php:65'],
        ['rel' => 'graph-sent-scan.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/remain_sql.php:59'],
        ['rel' => 'graph-sent-check.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/remain_sql.php:59'],
        ['rel' => 'graph-week-cache.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/graph_week.php:16'],
        ['rel' => 'graph-person-events.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/graph_events.php:16'],
        ['rel' => 'solkoll.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/solkoll.php:10'],
        ['rel' => 'solkoll-cache.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/solkoll.php:20'],
        ['rel' => 'gi/loans.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/shield.php:1378'],
        ['rel' => 'inkop-mallar.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/inkop_mallar.php:16'],
        ['rel' => 'external-queue.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/external_queue.php:16'],
        ['rel' => 'omtago/leads.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/omtago_leads.php:16'],
        ['rel' => 'notiser/mallar.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/notiser.php:1974'],
        ['rel' => 'app-errors-webhook-seen.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/security.php:1143'],
        ['rel' => 'app-errors-webhook-resolved.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/security.php:1148'],
        ['rel' => 'inkorg/messages.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/inkorg.php:530'],
        ['rel' => 'inkorg/threads.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/inkorg.php:552'],
        ['rel' => 'inkorg/contacts.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/kontakter_migrate.php:57'],
        ['rel' => 'inkorg/sent.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/inbox_sent.php:16'],
        ['rel' => 'inkorg/sent-new.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/inbox_sent_new.php:17'],
        ['rel' => 'inkorg/graph-state.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/inkorg_graph.php:54'],
        ['rel' => 'inkorg/helpscout-import.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/inkorg_helpscout_import.php:287'],
        ['rel' => 'inkorg/malmo-hs-sync.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/inkorg_malmo_hs.php:112'],
        ['rel' => 'inkorg/auto-send-cap.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/auto_send_cap.php:16'],
        ['rel' => 'inkorg/malmo-offer-sends.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/malmo_offer_sends.php:16'],
        ['rel' => 'inkorg/quote-preview.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/quote_preview.php:16'],
        ['rel' => 'inkorg/hs-uncertain-open.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/hs_uncertain_open.php:16'],
        ['rel' => 'inkorg/rebook-log.json', 'class' => 'inactive', 'reason' => 'SQL är lagret', 'evidence' => 'src/migrate/rebook_log.php:16'],
        ['rel' => 'inkorg/hs-import/', 'class' => 'inactive', 'reason' => 'SQL är arkivet', 'evidence' => 'src/inkorg_helpscout_import.php:1745', 'prefix' => true],
    ];
}

function jeab_json_classify(string $rel): array
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $forced = jeab_json_force_active($rel);
    if ($forced !== null) {
        return $forced;
    }
    $exact = null;
    $prefix = null;
    $prefixLen = -1;
    foreach (jeab_json_registry() as $row) {
        if (!empty($row['prefix'])) {
            $stem = rtrim((string) $row['rel'], '/');
            $head = $stem . '/';
            if ($rel === $stem || str_starts_with($rel, $head)) {
                if (strlen($head) > $prefixLen) {
                    $prefix = $row;
                    $prefixLen = strlen($head);
                }
            }
            continue;
        }
        if ((string) $row['rel'] === $rel) {
            $exact = $row;
        }
    }
    $hit = $exact ?? $prefix;
    if ($hit === null) {
        return ['class' => 'unknown', 'reason' => '', 'evidence' => ''];
    }

    return [
        'class' => (string) $hit['class'],
        'reason' => (string) $hit['reason'],
        'evidence' => (string) $hit['evidence'],
    ];
}

/** Tvingar aktiv även om ett prefix skulle säga inactive. */
function jeab_json_force_active(string $rel): ?array
{
    $base = basename($rel);
    if ($rel === 'rot' || str_starts_with($rel, 'rot/') || str_contains($rel, '/rot/')) {
        return ['class' => 'active', 'reason' => 'rot rörs inte', 'evidence' => 'src/shield.php:467'];
    }
    if ($base === 'shield.php' || $rel === 'src/shield.php') {
        return ['class' => 'active', 'reason' => 'shield rörs inte', 'evidence' => 'src/shield.php:1'];
    }
    if ($base === 'store.json') {
        return ['class' => 'active', 'reason' => 'bokningar rörs inte', 'evidence' => 'src/store.php:499'];
    }
    if (preg_match('#(^|/)(times|booking-times|bokningstider)(\.|/)#', $rel) === 1) {
        return ['class' => 'active', 'reason' => 'bokningstider rörs inte', 'evidence' => 'src/ordrar.php:2642'];
    }
    if ($base === 'passwords.json' || $base === '.passwords.json' || str_contains(strtolower($base), 'token') || str_starts_with($rel, 'secrets/')) {
        return null;
    }

    return null;
}

/** Samma fil via två rötter (katalog, symlink eller hårdlänk) får en nyckel. */
function jeab_json_path_key(string $path): string
{
    $stat = @stat($path);
    if (is_array($stat) && isset($stat['dev'], $stat['ino'])) {
        return (string) $stat['dev'] . ':' . (string) $stat['ino'];
    }
    $real = realpath($path);
    if (is_string($real) && $real !== '') {
        return 'p:' . str_replace('\\', '/', $real);
    }

    return 'p:' . str_replace('\\', '/', $path);
}

function jeab_json_inside_roots(string $path): bool
{
    $norm = rtrim(str_replace('\\', '/', $path), '/');
    $roots = [jeab_data_dir()];
    if (function_exists('jeab_public_data_dir')) {
        $roots[] = jeab_public_data_dir();
    }
    foreach ($roots as $root) {
        if ($root === '') {
            continue;
        }
        $real = realpath($root);
        $base = rtrim(str_replace('\\', '/', is_string($real) && $real !== '' ? $real : $root), '/');
        if ($base !== '' && ($norm === $base || str_starts_with($norm, $base . '/'))) {
            return true;
        }
    }

    return false;
}

function jeab_json_inventory_skip(string $rel): bool
{
    $rel = trim(str_replace('\\', '/', $rel), '/');

    return $rel === 'trash'
        || str_starts_with($rel, 'trash/')
        || str_contains($rel, '/trash/')
        || $rel === 'backups'
        || str_starts_with($rel, 'backups/')
        || str_contains($rel, '/backups/');
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_json_inventory_rows(): array
{
    $roots = [
        'private' => rtrim(jeab_data_dir(), '/'),
        'public' => function_exists('jeab_public_data_dir') ? rtrim(jeab_public_data_dir(), '/') : '',
    ];
    $seenRoot = [];
    $seenFile = [];
    $rows = [];
    foreach ($roots as $name => $dir) {
        if ($dir === '' || !is_dir($dir)) {
            continue;
        }
        $rootKey = jeab_json_path_key($dir);
        if (isset($seenRoot[$rootKey])) {
            continue;
        }
        $seenRoot[$rootKey] = true;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
        } catch (Throwable) {
            continue;
        }
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (!str_ends_with(strtolower($file->getFilename()), '.json')) {
                continue;
            }
            $fileKey = jeab_json_path_key($file->getPathname());
            if (isset($seenFile[$fileKey])) {
                continue;
            }
            $seenFile[$fileKey] = true;
            $abs = str_replace('\\', '/', $file->getPathname());
            $rootPath = str_replace('\\', '/', $dir);
            if (!str_starts_with($abs, $rootPath . '/')) {
                continue;
            }
            $rel = substr($abs, strlen($rootPath) + 1);
            if (jeab_json_inventory_skip($rel)) {
                continue;
            }
            $class = jeab_json_classify($rel);
            $bytes = (int) $file->getSize();
            $rows[] = [
                'root' => $name,
                'rel' => $rel,
                'bytes' => $bytes,
                'mtime' => (int) $file->getMTime(),
                'class' => $class['class'],
                'reason' => $class['reason'],
                'evidence' => $class['evidence'],
                'large' => $class['class'] === 'active' && $bytes > 102400,
            ];
        }
    }
    usort($rows, static function (array $a, array $b): int {
        return [$a['root'], $a['rel']] <=> [$b['root'], $b['rel']];
    });

    return $rows;
}

function jeab_json_trash_unused_rels(): array
{
    $rels = [];
    foreach (jeab_json_inventory_rows() as $row) {
        if (($row['class'] ?? '') !== 'inactive') {
            continue;
        }
        $rel = (string) ($row['rel'] ?? '');
        if ($rel === '' || jeab_json_trash_block_reason($rel) !== null) {
            continue;
        }
        $rels[$rel] = true;
    }

    return array_keys($rels);
}


function jeab_json_trash_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_json_trash_block_reason(string $rel): ?string
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || str_contains($rel, "\0") || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.(/|$)#', $rel) === 1) {
        return 'ogiltig sökväg';
    }
    $base = basename($rel);
    if ($base === 'shield.php' || $rel === 'src/shield.php') {
        return 'shield.php';
    }
    if ($rel === 'rot' || str_starts_with($rel, 'rot/') || str_contains($rel, '/rot/')) {
        return 'rot';
    }
    if ($base === 'store.json') {
        return 'bokningar';
    }
    if (preg_match('#(^|/)(times|booking-times|bokningstider)(\.|/)#', $rel) === 1) {
        return 'bokningstider';
    }
    if ($base === 'passwords.json' || $base === '.passwords.json') {
        return 'lösenord';
    }
    if (str_contains(strtolower($base), 'token')) {
        return 'token';
    }
    if ($rel === 'secrets' || str_starts_with($rel, 'secrets/') || str_contains($rel, '/secrets/')) {
        return 'hemlighet';
    }

    return null;
}

function jeab_json_trash_day(?string $day): string
{
    $day = $day === null || $day === '' ? gmdate('Y-m-d') : $day;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
        throw new InvalidArgumentException('ogiltigt datum');
    }

    return $day;
}

function jeab_json_trash_unique(string $dest): string
{
    if (!file_exists($dest)) {
        return $dest;
    }
    for ($n = 2; $n <= 50; $n++) {
        $candidate = $dest . '-' . $n;
        if (!file_exists($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('papperskorgen har redan 50 kopior');
}

/**
 * @param list<string> $rels
 * @return array<string, mixed>
 */
function jeab_json_trash_apply(array $rels, bool $dry, ?string $day = null): array
{
    $day = jeab_json_trash_day($day);
    $private = rtrim(jeab_data_dir(), '/');
    $public = function_exists('jeab_public_data_dir') ? rtrim(jeab_public_data_dir(), '/') : '';
    $sameRoot = $public !== '' && jeab_json_path_key($public) === jeab_json_path_key($private);
    $moved = [];
    $missing = [];
    $refused = [];
    $taken = [];
    foreach ($rels as $rel) {
        $rel = str_replace('\\', '/', (string) $rel);
        $why = jeab_json_trash_block_reason($rel);
        if ($why !== null) {
            $refused[] = ['rel' => $rel, 'why' => $why];
            continue;
        }
        if ((jeab_json_classify($rel)['class'] ?? '') !== 'inactive') {
            $refused[] = ['rel' => $rel, 'why' => 'inte inactive'];
            continue;
        }
        $sources = [];
        $push = static function (string $abs, string $suffix) use (&$sources, &$taken, &$refused, $rel): void {
            if (!is_file($abs)) {
                return;
            }
            $real = realpath($abs);
            $canon = is_string($real) && $real !== '' ? $real : $abs;
            if (!jeab_json_inside_roots(str_replace('\\', '/', $canon))) {
                $refused[] = ['rel' => $rel, 'why' => 'ogiltig sökväg'];

                return;
            }
            $key = jeab_json_path_key($canon);
            if (isset($taken[$key])) {
                return;
            }
            $taken[$key] = true;
            $sources[] = ['abs' => $canon, 'suffix' => $suffix];
        };
        $push($private . '/' . $rel, $rel);
        if ($public !== '' && !$sameRoot) {
            $push($public . '/' . $rel, 'public/' . $rel);
        }
        if ($sources === []) {
            $missing[] = $rel;
            continue;
        }
        if ($dry) {
            $missing[] = $rel . ' (kvar, dry)';
            continue;
        }
        foreach ($sources as $src) {
            $dest = jeab_json_trash_unique($private . '/trash/' . $day . '/' . $src['suffix']);
            $dir = dirname($dest);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $refused[] = ['rel' => $rel, 'why' => 'kunde inte skapa katalog'];
                continue;
            }
            if (!@rename($src['abs'], $dest)) {
                $refused[] = ['rel' => $rel, 'why' => 'flytten misslyckades'];
                continue;
            }
            $moved[] = ['rel' => $src['suffix'], 'to' => $dest];
        }
    }
    $manifest = null;
    if (!$dry) {
        $keep = (new DateTimeImmutable($day . ' 00:00:00 UTC'))->modify('+14 days')->format('Y-m-d');
        $dir = $private . '/trash/' . $day;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('kunde inte skapa papperskorgen');
        }
        $doc = [
            'action' => 'json.trash_unused',
            'at' => gmdate('c'),
            'day' => $day,
            'keep_until' => $keep,
            'dry' => 0,
            'moved' => $moved,
            'missing' => $missing,
            'refused' => $refused,
        ];
        $manifest = jeab_json_trash_unique($dir . '/manifest.json');
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($manifest, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('kunde inte skriva manifestet');
        }
    }

    return [
        'ok' => $refused === [],
        'dry' => $dry ? 1 : 0,
        'day' => $day,
        'moved' => $moved,
        'missing' => $missing,
        'refused' => $refused,
        'manifest' => $manifest,
        'rels' => array_values($rels),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_json_trash_unused_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    unset($input['rels'], $input['paths'], $input['files']);
    $result = jeab_json_trash_apply(jeab_json_trash_unused_rels(), jeab_json_trash_dry($input), null);
    $result['status'] = 200;
    $result['ok'] = true;

    return $result;
}

/**
 * Läser bara. Skapar inga kataloger och skriver inget manifest.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_json_inventory_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    unset($input['rels'], $input['paths'], $input['files']);
    $rows = jeab_json_inventory_rows();
    $counts = ['active' => 0, 'inactive' => 0, 'unknown' => 0];
    $large = [];
    foreach ($rows as $row) {
        $class = (string) ($row['class'] ?? '');
        if (isset($counts[$class])) {
            $counts[$class]++;
        }
        if (!empty($row['large'])) {
            $large[] = ['root' => (string) $row['root'], 'rel' => (string) $row['rel'], 'bytes' => (int) $row['bytes']];
        }
    }

    return [
        'ok' => true,
        'status' => 200,
        'read_only' => 1,
        'files' => $rows,
        'counts' => $counts,
        'large_active' => $large,
    ];
}
