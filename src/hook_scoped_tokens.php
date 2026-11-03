<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Agent-token per hook. Bara SHA-256-hex av token ligger här, aldrig själva token.
 * Tester kan lägga till poster via $GLOBALS['_JEAB_HOOK_SCOPED_TOKENS'].
 *
 * @var array<string, array{name:string, scopes:list<string>}>
 */
const JEAB_HOOK_SCOPED_TOKENS = [
    '270ec26eb71511fd57caff4f821122a9cef78d3ac9128a077dbaaf64119b459c' => [
        'name' => 'bokmalen',
        'scopes' => [
            'fortnox.kund_*',
            'fortnox.faktura_*',
            'fortnox.leveransdatum_satt',
            'fortnox.order_relink',
            'fortnox.order_attach',
            'fortnox.order_attach_undo',
            'fortnox.invoice_order_lookup',
            'fortnox.invoice_compare',
            'fortnox.order_export',
            'fortnox.orders_read',
            'fortnox.pnr_check',
            'kontakter.import_new',
        ],
    ],
];

/**
 * @return array<string, array{name:string, scopes:list<string>}>
 */
function jeab_hook_scoped_token_entries(): array
{
    $entries = JEAB_HOOK_SCOPED_TOKENS;
    $extra = $GLOBALS['_JEAB_HOOK_SCOPED_TOKENS'] ?? null;
    if (!is_array($extra)) {
        return $entries;
    }
    foreach ($extra as $hash => $row) {
        if (!is_string($hash) || !is_array($row)) {
            continue;
        }
        $entries[$hash] = $row;
    }
    return $entries;
}

function jeab_hook_scoped_bearer(): string
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return '';
    }
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S.*)$/i', $auth, $match) !== 1) {
        return '';
    }
    return trim($match[1]);
}

function jeab_hook_scope_covers(string $scope, string $action): bool
{
    $scope = trim($scope);
    $action = trim($action);
    if ($scope === '' || $action === '' || str_contains($scope, "\n") || str_contains($action, "\n")) {
        return false;
    }
    $pattern = '/\A' . str_replace('\*', '.*', preg_quote($scope, '/')) . '\z/';
    return preg_match($pattern, $action) === 1;
}

function jeab_hook_scoped_log(string $name, string $bearer): void
{
    static $logged = [];
    if ($name === '' || preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $name) !== 1) {
        return;
    }
    if ($bearer !== '' && str_contains($name, $bearer)) {
        return;
    }
    if (isset($logged[$name])) {
        return;
    }
    $logged[$name] = true;
    error_log('hook-token ' . $name);
}

function jeab_hook_scoped_authorized(string $action): bool
{
    $bearer = jeab_hook_scoped_bearer();
    if ($bearer === '' || $action === '') {
        return false;
    }
    $given = hash('sha256', $bearer);
    $matched = null;
    foreach (jeab_hook_scoped_token_entries() as $hash => $entry) {
        $hash = strtolower(trim((string) $hash));
        if (strlen($hash) !== 64 || !ctype_xdigit($hash) || !is_array($entry)) {
            continue;
        }
        if (!hash_equals($hash, $given)) {
            continue;
        }
        $matched = $entry;
    }
    if (!is_array($matched)) {
        return false;
    }
    $scopes = $matched['scopes'] ?? [];
    if (!is_array($scopes)) {
        return false;
    }
    foreach ($scopes as $scope) {
        if (is_string($scope) && jeab_hook_scope_covers($scope, $action)) {
            jeab_hook_scoped_log((string) ($matched['name'] ?? ''), $bearer);
            return true;
        }
    }
    return false;
}
