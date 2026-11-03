<?php

declare(strict_types=1);

/**
 * Scoped agent-token. Bara hashen injiceras. Själva token finns inte i repot.
 */

$tmp = sys_get_temp_dir() . '/jeab-scoped-hook-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
mkdir($tmp . '/public', 0775, true);
putenv('JEAB_DISABLE_SQLITE=1');
$incoming = 'incoming-hook-token';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => $incoming,
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$logFile = $tmp . '/php-error.log';
ini_set('error_log', $logFile);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/hook_scoped_tokens.php';
require_once dirname(__DIR__) . '/src/fortnox_pnr.php';
require_once dirname(__DIR__) . '/src/kontakter_import_new.php';

function scoped_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$agent = 'scoped-test-token';
$hash = hash('sha256', $agent);
$GLOBALS['_JEAB_HOOK_SCOPED_TOKENS'] = [
    $hash => [
        'name' => 'test-agent',
        'scopes' => [
            'fortnox.kund_*',
            'fortnox.faktura_*',
            'fortnox.leveransdatum_satt',
            'fortnox.pnr_check',
            'kontakter.import_new',
        ],
    ],
];

$bokmalen = JEAB_HOOK_SCOPED_TOKENS['270ec26eb71511fd57caff4f821122a9cef78d3ac9128a077dbaaf64119b459c'] ?? null;
scoped_assert(is_array($bokmalen) && ($bokmalen['name'] ?? '') === 'bokmalen', 'bokmalen ligger som hash');
$scopes = is_array($bokmalen) ? ($bokmalen['scopes'] ?? []) : [];
$covers = static function (string $action) use ($scopes): bool {
    foreach ($scopes as $scope) {
        if (is_string($scope) && jeab_hook_scope_covers($scope, $action)) {
            return true;
        }
    }
    return false;
};
scoped_assert($covers('fortnox.kund_hamta') && $covers('fortnox.leveransdatum_satt') && $covers('kontakter.import_new'), 'bokmalen-scopet täcker kund, leveransdatum och import');
scoped_assert($covers('fortnox.order_relink') && $covers('fortnox.order_attach') && $covers('fortnox.order_attach_undo') && $covers('fortnox.invoice_order_lookup') && $covers('fortnox.invoice_compare') && $covers('fortnox.order_export') && $covers('fortnox.orders_read'), 'bokmalen får relink, attach, undo, lookup, compare, order_export och orders_read');
scoped_assert(!$covers('inbox.status') && !$covers('inbox.find_by_hs') && !$covers('kontakter.dedupe'), 'bokmalen-scopet täcker inte andra åtgärder');
scoped_assert(count(JEAB_HOOK_SCOPED_TOKENS) === 1 && array_key_first(JEAB_HOOK_SCOPED_TOKENS) === '270ec26eb71511fd57caff4f821122a9cef78d3ac9128a077dbaaf64119b459c', 'bara bokmalen-hashen finns och den är oförändrad');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/hook_scoped_tokens.php');
scoped_assert(!str_contains($src, $agent) && str_contains($src, 'hash_equals'), 'källan har hashen och hash_equals, inte testtoken');

$_SERVER['REQUEST_METHOD'] = 'POST';
$store = jeab_boot();
scoped_assert($store instanceof JeabStore, 'store finns');

$as = static function (string $bearer): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
    unset($_SERVER['HTTP_X_WEBHOOK_TOKEN']);
};

$as($agent);
@unlink($logFile);
$hamta = jeab_fortnox_kund_hamta([]);
scoped_assert(($hamta['status'] ?? 0) !== 401 && ($hamta['error'] ?? '') !== 'Fel token.', 'scoped token accepteras för fortnox.kund_hamta');
scoped_assert(jeab_fortnox_lon_hook_authorized($store, 'fortnox.leveransdatum_satt'), 'scoped token accepteras för fortnox.leveransdatum_satt');
$logged = is_file($logFile) ? (string) file_get_contents($logFile) : '';
scoped_assert(str_contains($logged, 'hook-token test-agent') && !str_contains($logged, $agent) && !str_contains($logged, $hash), 'loggen har bara tokennamnet');

scoped_assert(!jeab_hook_scoped_authorized('inbox.status'), 'scoped token nekas för inbox.*');
scoped_assert(!jeab_hook_scoped_authorized('inbox.find_by_hs'), 'inbox.find_by_hs nekas');
scoped_assert(!jeab_fortnox_lon_hook_authorized($store, 'fortnox.order_relink') && !jeab_fortnox_lon_hook_authorized($store, 'fortnox.order_attach') && !jeab_fortnox_lon_hook_authorized($store, 'fortnox.order_attach_undo') && !jeab_fortnox_lon_hook_authorized($store, 'fortnox.invoice_order_lookup') && !jeab_fortnox_lon_hook_authorized($store, 'fortnox.invoice_compare') && !jeab_fortnox_lon_hook_authorized($store, 'fortnox.order_export'), 'test-agenten har inte ompekning, attach, undo, lookup, compare eller export');
scoped_assert(!jeab_inkorg_hook_authorized(), 'inkorgsgrinden tar inte scoped token');
scoped_assert(!jeab_fortnox_lon_hook_authorized($store, 'fortnox.not_in_scope'), 'scoped token nekas för åtgärd utanför scopet');
scoped_assert(!jeab_hook_scoped_authorized('kontakter.dedupe'), 'scoped token nekas för andra kontakter-åtgärder');
scoped_assert(!jeab_fortnox_pnr_authorized('fortnox.not_in_scope'), 'pnr-grinden nekar åtgärd utanför scopet');

$as('fel-token');
$denied = jeab_fortnox_kund_hamta(['account' => 'JEAB']);
scoped_assert(($denied['status'] ?? 0) === 401 && ($denied['error'] ?? '') === 'Fel token.', 'fel token nekas för kund_hamta');
scoped_assert(!jeab_fortnox_lon_hook_authorized($store, 'fortnox.leveransdatum_satt'), 'fel token nekas för leveransdatum_satt');
$importDenied = jeab_kontakter_import_new_dispatch(['action' => 'kontakter.import_new', 'rows' => []]);
scoped_assert(($importDenied['status'] ?? 0) === 401 && ($importDenied['error'] ?? '') === 'Fel token.', 'fel token nekas för kontakter.import_new');

$as($hash);
scoped_assert(!jeab_fortnox_lon_hook_authorized($store, 'fortnox.kund_hamta'), 'själva hashen är inte en giltig token');

$as($incoming);
scoped_assert(jeab_fortnox_lon_hook_authorized($store, 'fortnox.not_in_scope'), 'inkommande token fungerar utanför scopet');
$hamtaIn = jeab_fortnox_kund_hamta([]);
scoped_assert(($hamtaIn['status'] ?? 0) !== 401 && ($hamtaIn['error'] ?? '') !== 'Fel token.', 'inkommande token fungerar för fortnox.kund_hamta');
scoped_assert(jeab_fortnox_lon_hook_authorized($store, 'fortnox.leveransdatum_satt'), 'inkommande token fungerar för leveransdatum_satt');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-import-inkorg']));
$as('tok-import-inkorg');
$importIn = jeab_kontakter_import_new_dispatch(['action' => 'kontakter.import_new', 'rows' => [], 'company' => 'jarfalla']);
scoped_assert(($importIn['status'] ?? 0) === 200 && !empty($importIn['ok']) && ($importIn['dry'] ?? null) === true, 'inkorgstoken fungerar och dry är standard');

$as($agent);
$importScoped = jeab_kontakter_import_new_dispatch(['action' => 'kontakter.import_new', 'rows' => [], 'bolag' => 'malmo']);
scoped_assert(($importScoped['status'] ?? 0) === 200 && !empty($importScoped['ok']), 'scoped token accepteras för kontakter.import_new');

$pnrSrc = (string) file_get_contents(dirname(__DIR__) . '/src/fortnox_pnr.php');
scoped_assert(str_contains($pnrSrc, "!== 'SAM'"), 'confirm=SAM är kvar');

echo "Alla scoped-token-tester OK\n";
