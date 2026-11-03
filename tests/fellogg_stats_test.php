<?php

declare(strict_types=1);

/** Saknad webhook-hemlighet loggas och släpps. fellogg.stats läser, utan att visa hemligheten. */

$tmp = sys_get_temp_dir() . '/jeab-fellogg-stats-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_WEBHOOK_SYNC'] = true;
unset($GLOBALS['_JEAB_EINSTEIN_SECRET']);
$err = $tmp . '/php-error.log';
ini_set('log_errors', '1');
ini_set('error_log', $err);
require dirname(__DIR__) . '/src/bootstrap.php';

function fl(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$marker = 'einstein-webhook-secret saknas';
jeab_log_app_error('yta', 'första felet');
jeab_log_app_error('yta', 'första felet');
$seen = (string) @file_get_contents(jeab_log_app_error_webhook_seen_path());
$log = jeab_app_errors_read(50);
$messages = array_map(static fn(array $row): string => (string) ($row['message'] ?? ''), $log);
$surfaces = array_map(static fn(array $row): string => (string) ($row['surface'] ?? ''), $log);
fl(!str_contains($seen, '"fingerprint"') || !preg_match('/"fingerprint"\s*:/', $seen) || $seen === '' || $seen === '{}' || $seen === '[]', 'spärren är släppt: ' . $seen);
$orig = '';
foreach ($log as $row) {
    if ((string) ($row['message'] ?? '') === 'första felet') {
        $orig = (string) ($row['fingerprint'] ?? '');
        fl((int) ($row['count'] ?? 0) >= 2, 'samma fel försökte igen');
    }
}
fl($orig !== '' && !str_contains($seen, $orig), 'ursprungligt fingeravtryck är inte spärrat');
$markerCount = 0;
foreach ($log as $row) {
    if ((string) ($row['message'] ?? '') === $marker) {
        $markerCount = (int) ($row['count'] ?? 0);
    }
}
fl(in_array($marker, $messages, true) && in_array('fellogg', $surfaces, true) && $markerCount === 1, 'markören skrivs en gång per timme');
$errText = is_file($err) ? (string) file_get_contents($err) : '';
fl(substr_count($errText, 'fellogg: ' . $marker) === 1, 'error_log får en rad per timme');
$GLOBALS['_JEAB_NOW'] = time() + 3601;
jeab_log_app_error('yta', 'första felet');
$later = jeab_app_errors_read(50);
$laterCount = 0;
foreach ($later as $row) {
    if ((string) ($row['message'] ?? '') === $marker) {
        $laterCount = (int) ($row['count'] ?? 0);
    }
}
fl($laterCount === 2 && substr_count((string) file_get_contents($err), 'fellogg: ' . $marker) === 2, 'ny timme loggar saknad hemlighet igen');

$secret = 'HEMLIGT-' . bin2hex(random_bytes(8));
$before = jeab_fellogg_stats([]);
fl(($before['secret_readable'] ?? true) === false, 'saknad hemlighetsfil är oläsbar');
$secretDir = dirname(jeab_einstein_webhook_secret_path());
if (!is_dir($secretDir)) {
    mkdir($secretDir, 0775, true);
}
file_put_contents(jeab_einstein_webhook_secret_path(), json_encode([
    'url' => 'https://hooks.flowstack.example.test/bug',
    'key' => $secret,
], JSON_UNESCAPED_UNICODE));
$fp = hash('sha256', 'stats-probe');
$path = jeab_app_errors_path();
file_put_contents($path, json_encode(['time' => '2026-01-01 08:00:00', 'surface' => 'stats', 'message' => 'äldre', 'fingerprint' => $fp], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
file_put_contents($path, json_encode(['time' => '2026-01-02 09:00:00', 'surface' => 'stats', 'message' => 'nyare', 'fingerprint' => $fp], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
jeab_log_app_error_resolved_put('resolved-probe', 'test', 'klar');
$one = jeab_fellogg_stats(['fingerprint' => $fp]);
$miss = jeab_fellogg_stats(['fingerprint' => 'saknas-helt']);
$all = jeab_fellogg_stats([]);
$encoded = json_encode($all, JSON_UNESCAPED_UNICODE) ?: '';
$row = (array) (($one['fingerprints'] ?? [])[0] ?? []);
fl(($one['ok'] ?? false) === true && (int) ($row['count'] ?? 0) === 2 && (string) ($row['latest'] ?? '') === '2026-01-02 09:00:00', 'räknar och tar senaste tid per fingeravtryck');
$prefix = substr($fp, 0, 8);
$prefixed = jeab_fellogg_stats(['fingerprint' => $prefix]);
$prefixedRow = (array) (($prefixed['fingerprints'] ?? [])[0] ?? []);
fl(strlen($prefix) === 8 && (int) ($prefixedRow['count'] ?? 0) === 2 && (string) ($prefixedRow['fingerprint'] ?? '') === $fp, 'prefix på minst 8 hex träffar fingeravtrycket');
$note = 'ANTECKNING-' . $secret;
$resolvedKey = substr($fp, 0, 12);
jeab_log_app_error_resolved_put($resolvedKey, 'test', $note);
$covered = jeab_fellogg_stats(['fingerprint' => $fp]);
$open = jeab_fellogg_stats(['fingerprint' => hash('sha256', 'annat-fel')]);
fl(($covered['covered_by_resolved'] ?? false) === true && (string) ($covered['resolved_prefix'] ?? '') === $resolvedKey, 'löst nyckelprefix följer med');
fl(($open['covered_by_resolved'] ?? true) === false && (string) ($open['resolved_prefix'] ?? 'x') === '', 'olöst fingeravtryck saknar täckning');
fl((int) (($miss['fingerprints'][0]['count'] ?? 1)) === 0 && (string) (($miss['fingerprints'][0]['latest'] ?? 'x')) === '', 'okänt fingeravtryck är tomt');
fl(($all['secret_readable'] ?? false) === true && (int) ($all['resolved'] ?? 0) >= 1 && ($all['covered_by_resolved'] ?? true) === false, 'läsbar fil och antal lösta');
$encodedCovered = json_encode($covered, JSON_UNESCAPED_UNICODE) ?: '';
fl(!str_contains($encoded, $secret) && !str_contains($encoded, 'hooks.flowstack.example.test') && !str_contains($encodedCovered, $note), 'svaret innehåller inte hemligheten');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
fl(str_contains($hook, "=== 'fellogg.stats'") && str_contains($hook, 'jeab_inkorg_hook_authorized'), 'fellogg.stats kräver token');
